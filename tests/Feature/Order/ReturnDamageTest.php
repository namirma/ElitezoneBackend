<?php

declare(strict_types=1);

namespace Tests\Feature\Order;

use App\Models\DebtEntry;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\ReturnDamage;
use App\Models\Shipment;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Financial\DebtEngine;
use App\Services\Shipping\ReturnDamageService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * فاز ۱۱ — بند ۳.۱۱ و ۸.۳ سند، به‌علاوه‌ی پاسخ مدیر (دور ۲، پرسش ۹ و ۱۰).
 *
 * اصل مالکیت که همه‌ی ادعاهای زیر از آن می‌آیند: کالا از آنِ خریدار و پول از
 * آنِ تأمین‌کننده. با عودت، تأمین‌کننده بدهکار نمی‌شود — خریدار می‌شود.
 */
class ReturnDamageTest extends TestCase
{
    use DatabaseTransactions;

    private User $buyer;

    private User $supplier;

    private Order $order;

    private Shipment $shipment;

    private ReturnDamageService $service;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();

        $system = User::factory()->create();
        config(['financial.system_user_id' => $system->id]);
        Wallet::create([Wallet::USER_ID => $system->id, Wallet::BALANCE => 0, Wallet::BALANCE_HOLD => 0]);

        $this->buyer = User::factory()->create(['mobile' => '09121110000']);
        $this->supplier = User::factory()->create(['mobile' => '09122220000']);

        Wallet::create([Wallet::USER_ID => $this->buyer->id, Wallet::BALANCE => 50_000_000, Wallet::BALANCE_HOLD => 0]);
        Wallet::create([Wallet::USER_ID => $this->supplier->id, Wallet::BALANCE => 0, Wallet::BALANCE_HOLD => 0]);

        $this->order = Order::create([
            Order::PLACER_USER_ID => $this->buyer->id,
            Order::ORDER_NUMBER => 'RTN-'.Str::random(8),
            Order::STATUS => Order::STATUS_RETURNED_TO_ORIGIN,
            Order::PAYMENT_STATUS => Order::PAYMENT_PAID,
            Order::FINAL_AMOUNT => 12_000_000,
            Order::SUBTOTAL => 10_000_000,
        ]);

        OrderItem::factory()->create([
            'order_id' => $this->order->id,
            'supplier_user_id' => $this->supplier->id,
        ]);

        $this->shipment = Shipment::create([
            Shipment::ORDER_ID => $this->order->id,
            Shipment::WEIGHT => 1000,
            Shipment::SHIPPING_COST => 0,
            Shipment::STATUS => Shipment::STATUS_RETURNED,
        ]);
        $this->shipment->forceFill([
            Shipment::RETURNED_AT => now(),
            Shipment::WAYBILL_REF => 'WB-OLD',
            Shipment::REGISTERED_AT => now()->subDay(),
        ])->save();
        $this->shipment->refresh();

        $this->service = app(ReturnDamageService::class);
    }

    private function record(float $amount = 1_000_000): ReturnDamage
    {
        return $this->service->record($this->shipment, $amount, 'کرایه عودت');
    }

    private function fullyApprove(float $amount = 1_000_000): ReturnDamage
    {
        $damage = $this->record($amount);
        $this->service->approveByBackoffice($damage, 1);

        return $this->service->approveByBuyer($damage->fresh());
    }

    // ── ثبت و جریان تأیید ────────────────────────────────────────────────────

    #[Test]
    public function a_damage_can_only_be_recorded_on_a_returned_shipment(): void
    {
        $inTransit = Shipment::create([
            Shipment::ORDER_ID => $this->order->id,
            Shipment::WEIGHT => 500,
            Shipment::SHIPPING_COST => 0,
            Shipment::STATUS => Shipment::STATUS_SHIPPED,
        ]);

        $this->expectExceptionMessage(__('shipments.damage_only_on_returned'));
        $this->service->record($inTransit, 100, 'زودهنگام');
    }

    #[Test]
    public function the_buyer_cannot_approve_before_the_backoffice(): void
    {
        // ترتیب اجباری است: مبلغِ ادعایی باید پیش از رسیدن به مشتری بررسی شود.
        $damage = $this->record();

        $this->expectExceptionMessage(__('shipments.damage_needs_backoffice_first'));
        $this->service->approveByBuyer($damage);
    }

    #[Test]
    public function no_debt_is_recorded_until_the_buyer_approves(): void
    {
        $damage = $this->record();
        $engine = app(DebtEngine::class);

        $this->assertSame(0.0, $engine->balanceFor((int) $this->buyer->id));

        $this->service->approveByBackoffice($damage, 1);
        $this->assertSame(0.0, $engine->balanceFor((int) $this->buyer->id),
            'تأیید بک‌آفیس به تنهایی نباید چیزی را مالی کند');

        $this->service->approveByBuyer($damage->fresh());
        $this->assertSame(1_000_000.0, $engine->balanceFor((int) $this->buyer->id));
    }

    #[Test]
    public function the_debt_lands_on_the_buyer_never_the_supplier(): void
    {
        $this->fullyApprove();

        $engine = app(DebtEngine::class);
        $this->assertSame(1_000_000.0, $engine->balanceFor((int) $this->buyer->id));
        $this->assertSame(0.0, $engine->balanceFor((int) $this->supplier->id),
            'اصل مالکیت: با عودت، تأمین‌کننده بدهکار نمی‌شود');

        $entry = DebtEntry::where(DebtEntry::USER_ID, $this->buyer->id)->latest('id')->first();
        $this->assertSame(DebtEntry::TYPE_RETURN_DAMAGE, $entry->{DebtEntry::TYPE});
        $this->assertSame((int) $this->shipment->id, (int) $entry->{DebtEntry::SHIPMENT_ID});
    }

    #[Test]
    public function a_rejected_damage_never_becomes_debt(): void
    {
        $damage = $this->record();
        $this->service->approveByBackoffice($damage, 1);
        $this->service->reject($damage->fresh(), 'مبلغ مورد قبول نیست');

        $this->assertSame(ReturnDamage::STATE_REJECTED, $damage->fresh()->state());
        $this->assertSame(0.0, app(DebtEngine::class)->balanceFor((int) $this->buyer->id));
    }

    // ── پرداخت و حمل مجدد ────────────────────────────────────────────────────

    #[Test]
    public function settling_moves_money_buyer_to_supplier_and_clears_the_debt(): void
    {
        $damage = $this->fullyApprove();

        $buyerBefore = (float) Wallet::where(Wallet::USER_ID, $this->buyer->id)->value(Wallet::BALANCE);
        $supplierBefore = (float) Wallet::where(Wallet::USER_ID, $this->supplier->id)->value(Wallet::BALANCE);

        $this->service->settle($damage);

        $buyerAfter = (float) Wallet::where(Wallet::USER_ID, $this->buyer->id)->value(Wallet::BALANCE);
        $supplierAfter = (float) Wallet::where(Wallet::USER_ID, $this->supplier->id)->value(Wallet::BALANCE);

        $this->assertEqualsWithDelta($buyerBefore - 1_000_000, $buyerAfter, 0.01);
        $this->assertEqualsWithDelta($supplierBefore + 1_000_000, $supplierAfter, 0.01);
        $this->assertSame(0.0, app(DebtEngine::class)->balanceFor((int) $this->buyer->id));
    }

    #[Test]
    public function settling_reopens_the_shipment_for_another_run(): void
    {
        // «چرخه تکرارپذیر است» — بدون پاک‌شدنِ بارنامه، گاردِ idempotency در
        // RegisterShipmentAction حمل مجدد را ۴۰۹ می‌کرد.
        $damage = $this->fullyApprove();
        $this->service->settle($damage);

        $shipment = $this->shipment->fresh();
        $this->assertSame(Shipment::STATUS_PENDING, $shipment->{Shipment::STATUS});
        $this->assertNull($shipment->{Shipment::RETURNED_AT});
        $this->assertNull($shipment->{Shipment::REGISTERED_AT});
        $this->assertNull($shipment->{Shipment::WAYBILL_REF});
        $this->assertNull($shipment->{Shipment::DELIVERY_DEADLINE});
    }

    #[Test]
    public function a_second_settle_is_a_no_op(): void
    {
        $damage = $this->fullyApprove();
        $this->service->settle($damage);

        $balanceAfterFirst = (float) Wallet::where(Wallet::USER_ID, $this->buyer->id)->value(Wallet::BALANCE);

        $this->service->settle($damage->fresh());

        $this->assertEqualsWithDelta(
            $balanceAfterFirst,
            (float) Wallet::where(Wallet::USER_ID, $this->buyer->id)->value(Wallet::BALANCE),
            0.01
        );
    }

    // ── سقف ──────────────────────────────────────────────────────────────────

    #[Test]
    public function reaching_the_goods_value_transfers_the_goods_and_ends_the_order(): void
    {
        // subtotal = 10,000,000. دو خسارتِ پنج‌میلیونی دقیقاً به سقف می‌رسند.
        $this->fullyApprove(5_000_000);
        $this->shipment->forceFill([Shipment::RETURNED_AT => now()])->save();
        $this->fullyApprove(5_000_000);

        $this->order->refresh();

        $this->assertSame(Order::STATUS_CANCELLED, $this->order->{Order::STATUS});
        $this->assertSame(__('shipments.damage_cap_reached'), $this->order->{Order::CANCEL_REASON});
        $this->assertSame(0.0, app(DebtEngine::class)->balanceFor((int) $this->buyer->id),
            'بدهی با خودِ کالا تسویه می‌شود، نه با پول');
    }

    #[Test]
    public function below_the_cap_the_order_stays_in_the_return_cycle(): void
    {
        $this->fullyApprove(9_999_999);

        $this->order->refresh();
        $this->assertSame(Order::STATUS_RETURNED_TO_ORIGIN, $this->order->{Order::STATUS});
        $this->assertGreaterThan(0, app(DebtEngine::class)->balanceFor((int) $this->buyer->id));
    }

    #[Test]
    public function the_cap_settlement_is_an_adjustment_not_a_repayment(): void
    {
        // گزارشِ بدهی باید «کالا واگذار شد» را از «پول پرداخت شد» تشخیص دهد.
        $this->fullyApprove(10_000_000);

        $entry = DebtEntry::where(DebtEntry::USER_ID, $this->buyer->id)
            ->where(DebtEntry::AMOUNT, '<', 0)
            ->latest('id')
            ->first();

        $this->assertNotNull($entry);
        $this->assertSame(DebtEntry::TYPE_ADJUSTMENT, $entry->{DebtEntry::TYPE});
    }
}
