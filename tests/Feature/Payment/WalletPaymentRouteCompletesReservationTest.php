<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use App\Actions\Financial\ProcessPreScalePaymentAction;
use App\Actions\Financial\ProcessReservationPaymentAction;
use App\Events\Financial\TransactionProcessed;
use App\Events\Order\OrderPaid;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Financial\LedgerReconciler;
use App\Services\Invoice\InvoiceGenerator;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * ‏`POST /payment/wallet` روی سفارشِ رزروشده — بازبینی ۲۰۲۶-۰۹-۱۴ (مورد ۱).
 *
 * ‏`PAYABLE_STATUSES` فقط `reserved` است، پس تنها سفارشی که به این مسیر می‌رسد یک
 * رزروِ باز است. کنترلر آن را به `ProcessPreScalePaymentAction` می‌داد: کلِ POO
 * دوباره کسر می‌شد، سفارش `paid` می‌شد و بیعانه‌ی ۵۰ میلیونی برای همیشه در
 * `balance_hold` کیف سازه می‌ماند. گاردِ انقضای رزرو هم دور زده می‌شد.
 *
 * DatabaseTransactions: همه‌ی نوشتن‌ها برمی‌گردند.
 */
class WalletPaymentRouteCompletesReservationTest extends TestCase
{
    use DatabaseTransactions;

    private const START_BALANCE = 200_000_000.0;

    private const RESERVE = 50_000_000.0;

    private User $system;

    private User $buyer;

    private Order $order;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        Event::fake([OrderPaid::class, TransactionProcessed::class]);
        $this->mock(InvoiceGenerator::class)->shouldIgnoreMissing();

        $this->system = User::factory()->create();
        config(['financial.system_user_id' => $this->system->id, 'financial.vat_user_id' => $this->system->id]);
        Wallet::create([Wallet::USER_ID => $this->system->id, Wallet::BALANCE => 0, Wallet::BALANCE_HOLD => 0]);

        $this->buyer = User::factory()->create();
        Wallet::create([Wallet::USER_ID => $this->buyer->id, Wallet::BALANCE => self::START_BALANCE, Wallet::BALANCE_HOLD => 0]);

        $supplier = User::factory()->create();
        Wallet::create([Wallet::USER_ID => $supplier->id, Wallet::BALANCE => 0, Wallet::BALANCE_HOLD => 0]);

        $this->order = Order::create([
            Order::PLACER_USER_ID => $this->buyer->id,
            Order::PAYER_USER_ID => $this->buyer->id,
            Order::ORDER_NUMBER => 'WALLETRES-'.Str::random(10),
            Order::STATUS => Order::STATUS_PROCESSING,
            Order::FINAL_AMOUNT => 11_000_000,
            Order::PAYMENT_STATUS => Order::PAYMENT_PENDING,
            Order::SHIPPING_COST => 0,
            Order::WEIGHT_BEFORE_SCALE => 10,
        ]);
        OrderItem::factory()->create([
            OrderItem::ORDER_ID => $this->order->id,
            OrderItem::SUPPLIER_USER_ID => $supplier->id,
            OrderItem::UNIT_PRICE => 1_000_000,
            OrderItem::TOTAL_PRICE => 10_000_000,
            OrderItem::QUANTITY => 10,
        ]);
        $this->order->forceFill([Order::OFFICIAL_PRICE => 1_000_000, Order::VAT_RATE => 10])->save();

        app(ProcessReservationPaymentAction::class)->execute($this->order, self::RESERVE, now()->addHours(2), graceHours: 0);
        $this->order->refresh();
    }

    private function balanceOf(User $user): float
    {
        return (float) Wallet::where(Wallet::USER_ID, $user->id)->value(Wallet::BALANCE);
    }

    private function sazeHold(): float
    {
        return (float) Wallet::where(Wallet::USER_ID, $this->system->id)->value(Wallet::BALANCE_HOLD);
    }

    private function assertReservationUntouched(): void
    {
        $this->order->refresh();
        $this->assertSame(Order::STATUS_RESERVED, $this->order->status);
        $this->assertNotSame(Order::PAYMENT_PAID, $this->order->payment_status);
        $this->assertEqualsWithDelta(self::START_BALANCE - self::RESERVE, $this->balanceOf($this->buyer), 1.0);
        $this->assertEqualsWithDelta(self::RESERVE, $this->sazeHold(), 1.0);
    }

    #[Test]
    public function the_wallet_route_completes_a_reservation_and_releases_its_deposit(): void
    {
        $this->assertSame(Order::STATUS_RESERVED, $this->order->status);

        Sanctum::actingAs($this->buyer);
        $this->postJson('/api/payment/wallet', ['order_id' => $this->order->id])->assertOk();

        $this->order->refresh();
        $poo = (float) $this->order->{Order::PAYMENT_OF_OWNER};

        $this->assertSame(Order::STATUS_PROCESSING, $this->order->status);
        $this->assertSame(Order::PAYMENT_PAID, $this->order->payment_status);
        $this->assertGreaterThan(0.0, $poo);

        // خریدار **یک بار** POO پرداخته — بیعانه جزئی از آن حساب شده، نه اضافه بر آن.
        $this->assertEqualsWithDelta(self::START_BALANCE - $poo, $this->balanceOf($this->buyer), 1.0);
        $this->assertEqualsWithDelta(0.0, $this->sazeHold(), 0.5, 'بیعانه نباید در balance_hold سازه بماند.');
        $this->assertSame([], app(LedgerReconciler::class)->unbalancedOrders((int) $this->order->id));
    }

    #[Test]
    public function the_wallet_route_refuses_an_expired_reservation(): void
    {
        $this->order->forceFill([Order::RESERVE_DEADLINE => now()->subHour(), Order::RESERVE_GRACE_HOURS => 0])->save();

        Sanctum::actingAs($this->buyer);
        $this->postJson('/api/payment/wallet', ['order_id' => $this->order->id])
            ->assertStatus(422)
            ->assertJsonPath('message', __('orders.reserve_expired_cannot_complete'));

        $this->assertReservationUntouched();
    }

    #[Test]
    public function the_pre_scale_action_refuses_an_order_that_carries_a_reservation_deposit(): void
    {
        try {
            app(ProcessPreScalePaymentAction::class)->execute($this->order);
            $this->fail('ProcessPreScalePaymentAction must refuse an order with a reservation deposit.');
        } catch (\RuntimeException $e) {
            $this->assertSame(__('orders.prescale_refused_reserved_order'), $e->getMessage());
        }

        $this->assertReservationUntouched();
    }
}
