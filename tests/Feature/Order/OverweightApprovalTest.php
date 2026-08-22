<?php

declare(strict_types=1);

namespace Tests\Feature\Order;

use App\Actions\Financial\ProcessWeighbridgeResultAction;
use App\Actions\Order\RequestOverweightApprovalAction;
use App\Exceptions\BusinessException;
use App\Models\Badge;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Shipment;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Financial\SettlementCalculatorService;
use App\Services\Invoice\InvoiceGenerator;
use App\Services\SmsService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * جریان تأیید دومرحله‌ای اضافه‌بار — بند ۳.۷ و ۸.۲ گام ۷الف.
 *
 * ⚠️ **این تست یک بن‌بست را قفل می‌کند، نه یک قابلیت را.** پیش از فاز مربوطه،
 * سفارشی که اضافه‌بارش از تلورانس می‌گذشت به `awaiting_weight_correction`
 * می‌رفت و هیچ کدی بیرونش نمی‌آورد — نه اکشنی بود، نه روتی، و
 * `$weighableStatuses` هم این وضعیت را نمی‌پذیرفت. مرکزی‌ترین حالتِ اینجا
 * `both_approvals_resume_the_settlement` است: اگر کسی گاردِ
 * `isOverweightApproved()` را بردارد، تأیید خریدار سفارش را به همان شاخه
 * برمی‌گرداند و حلقه‌ی بی‌پایان می‌سازد.
 */
class OverweightApprovalTest extends TestCase
{
    use DatabaseTransactions;

    private RequestOverweightApprovalAction $action;

    private ProcessWeighbridgeResultAction $weighbridge;

    private User $buyer;

    private User $supplier;

    private Wallet $buyerWallet;

    private Order $order;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mock(InvoiceGenerator::class)
            ->shouldReceive('generateWeighbridgeDocs')
            ->andReturnNull();

        $this->mock(SmsService::class)
            ->shouldReceive('send')
            ->andReturn(true);

        $this->weighbridge = app(ProcessWeighbridgeResultAction::class);
        $this->action = app(RequestOverweightApprovalAction::class);

        $systemUser = User::factory()->create();
        config(['financial.system_user_id' => $systemUser->id]);
        config(['financial.vat_user_id' => $systemUser->id]);
        $systemWallet = Wallet::create([
            Wallet::USER_ID => $systemUser->id, Wallet::BALANCE => 0, Wallet::BALANCE_HOLD => 0,
        ]);

        $badge = Badge::firstOrCreate([Badge::CODE => 'pioneer'], [Badge::NAME => 'Pioneer']);

        $this->buyer = User::factory()->create([User::BADGE_ID => $badge->id]);
        $this->buyerWallet = Wallet::create([
            Wallet::USER_ID => $this->buyer->id, Wallet::BALANCE => 500_000_000, Wallet::BALANCE_HOLD => 0,
        ]);

        $this->supplier = User::factory()->create();
        Wallet::create([
            Wallet::USER_ID => $this->supplier->id, Wallet::BALANCE => 1_000_000, Wallet::BALANCE_HOLD => 0,
        ]);

        $calc = app(SettlementCalculatorService::class);
        $rates = $calc->getCommissionRates('pioneer');
        $vat = 10.0;
        $poo = $calc->calculatePOO(0, 1000, 10, $rates['ow_final_rate'], $vat, true, true);
        $pts = $calc->calculatePTS(0, 1000, 10, $rates['ow_final_rate'], $rates['ssg_final_rate'], $rates['co_final_rate'], $vat, true, true);

        $this->order = Order::create([
            Order::PLACER_USER_ID => $this->buyer->id,
            Order::BENEFICIARY_USER_ID => $this->buyer->id,
            Order::ORDER_NUMBER => 'TEST-OW-'.Str::random(12),
            Order::STATUS => Order::STATUS_PROCESSING,
            Order::PAYMENT_STATUS => Order::PAYMENT_PAID,
            Order::WEIGHT_BEFORE_SCALE => 10,
            Order::SHIPPING_COST => 0,
        ]);
        $this->order->forceFill([
            Order::OFFICIAL_PRICE => 1000,
            Order::VAT_RATE => $vat,
            Order::PAYMENT_OF_OWNER => $poo,
            Order::PAYMENT_TO_SUPPLIER => $pts,
            Order::TRANS_COMMISSION => true,
            Order::TRANS_VAT => true,
        ])->save();

        OrderItem::factory()->create([
            OrderItem::ORDER_ID => $this->order->id,
            OrderItem::SUPPLIER_USER_ID => $this->supplier->id,
            OrderItem::UNIT_PRICE => 1000,
            OrderItem::TOTAL_PRICE => 10000,
            OrderItem::QUANTITY => 10,
        ]);

        $systemWallet->update([Wallet::BALANCE => $poo - $pts]);
    }

    /** وزنِ ۲۰ در برابر ۱۰ = ۱۰۰٪ اضافه‌بار، بسیار فراتر از تلورانس ۲۰٪. */
    private function triggerOverTolerance(): void
    {
        $this->weighbridge->execute($this->order, 20.0);
        $this->order->refresh();
    }

    // ── گشودن درخواست ────────────────────────────────────────────────────────

    #[Test]
    public function exceeding_the_tolerance_opens_a_request_with_an_amount_and_a_deadline(): void
    {
        $this->triggerOverTolerance();

        $this->assertSame(Order::STATUS_AWAITING_WEIGHT_CORRECTION, $this->order->{Order::STATUS});
        $this->assertTrue($this->order->hasOpenOverweightRequest());
        $this->assertGreaterThan(0, (float) $this->order->{Order::OVERWEIGHT_PENDING_AMOUNT});
        $this->assertNotNull($this->order->{Order::OVERWEIGHT_DEADLINE});
        $this->assertNull($this->order->{Order::WEIGHING_COMPLETED_AT},
            'تا تأیید دو طرف هیچ تسویه‌ای ثبت نمی‌شود');
    }

    // ── ترتیب تأییدها ────────────────────────────────────────────────────────

    #[Test]
    public function the_buyer_cannot_approve_before_the_supplier(): void
    {
        $this->triggerOverTolerance();

        $this->expectException(BusinessException::class);
        $this->action->approveByBuyer($this->order);
    }

    #[Test]
    public function the_supplier_cannot_approve_twice(): void
    {
        $this->triggerOverTolerance();
        $this->action->approveBySupplier($this->order);

        $this->expectException(BusinessException::class);
        $this->action->approveBySupplier($this->order->refresh());
    }

    #[Test]
    public function approving_without_an_open_request_is_refused(): void
    {
        // سفارشِ processing، بدون هیچ درخواستی.
        $this->expectException(BusinessException::class);
        $this->action->approveBySupplier($this->order);
    }

    // ── مسیر موفق ────────────────────────────────────────────────────────────

    #[Test]
    public function both_approvals_resume_the_settlement(): void
    {
        $this->triggerOverTolerance();
        $balanceBefore = (float) $this->buyerWallet->fresh()->balance;

        $this->action->approveBySupplier($this->order);
        $result = $this->action->approveByBuyer($this->order->refresh());

        $this->order->refresh();

        $this->assertNotSame('awaiting_weight_correction', $result['status'],
            'با تأیید هر دو طرف، گاردِ تلورانس باید رد شود وگرنه حلقه‌ی بی‌پایان است');
        $this->assertNotNull($this->order->{Order::WEIGHING_COMPLETED_AT},
            'تعدیل واقعاً اجرا شده است');
        $this->assertLessThan($balanceBefore, (float) $this->buyerWallet->fresh()->balance,
            'مابه‌التفاوت اضافه‌بار از کیف پول خریدار کسر می‌شود');
        $this->assertEquals(0.0, (float) $this->order->{Order::OVERWEIGHT_PENDING_AMOUNT},
            'درخواست با اجرای تعدیل بسته می‌شود');
        $this->assertNull($this->order->{Order::OVERWEIGHT_DEADLINE},
            'مهلت پاک می‌شود تا کرانِ انقضا سفارشِ تسویه‌شده را باطل نکند');
    }

    #[Test]
    public function the_approval_stamps_survive_the_settlement(): void
    {
        $this->triggerOverTolerance();
        $this->action->approveBySupplier($this->order);
        $this->action->approveByBuyer($this->order->refresh());

        $this->order->refresh();

        // رد پای تصمیمِ دو طرف باید بماند — مبلغ و مهلت پاک می‌شوند، مهرها نه.
        $this->assertNotNull($this->order->{Order::OVERWEIGHT_SUPPLIER_APPROVED_AT});
        $this->assertNotNull($this->order->{Order::OVERWEIGHT_BUYER_APPROVED_AT});
        $this->assertTrue($this->order->isOverweightApproved());
    }

    // ── رد و انقضا ───────────────────────────────────────────────────────────

    #[Test]
    public function rejection_voids_the_waybill_and_returns_the_order_to_processing(): void
    {
        // ‏`waybill_ref` / `actual_weight` / `is_final` / `registered_at` عمداً
        // در `$fillable` نیستند (همان قاعده‌ی ستون‌های اسنپ‌شاتِ `Order`)، پس
        // باید با `forceFill` نوشته شوند وگرنه mass assignment بی‌صدا دورشان
        // می‌اندازد و گاردِ `whereNotNull(registered_at)` هیچ ردیفی نمی‌بیند.
        $shipment = Shipment::create([
            Shipment::ORDER_ID => $this->order->id,
            Shipment::STATUS => Shipment::STATUS_SHIPPED,
        ]);
        $shipment->forceFill([
            Shipment::WAYBILL_REF => 'WB-'.Str::random(6),
            Shipment::ACTUAL_WEIGHT => 20,
            Shipment::IS_FINAL => true,
            Shipment::REGISTERED_AT => now(),
        ])->save();

        $this->triggerOverTolerance();
        $balanceBefore = (float) $this->buyerWallet->fresh()->balance;

        $this->action->reject($this->order, 'buyer', 'وزن اعلامی درست نیست.');

        $this->order->refresh();
        $shipment->refresh();

        $this->assertSame(Order::STATUS_PROCESSING, $this->order->{Order::STATUS});
        $this->assertNull($this->order->{Order::WEIGHT_AFTER_SCALE},
            'وزنی که هیچ‌کس نپذیرفته نباید روی سفارش بماند');
        $this->assertFalse($this->order->hasOpenOverweightRequest());
        $this->assertSame(Shipment::STATUS_PENDING, $shipment->{Shipment::STATUS});
        $this->assertNull($shipment->{Shipment::REGISTERED_AT},
            'بدون پاک‌کردن registered_at، گاردِ idempotency ثبت مجدد بارنامه را ۴۰۹ می‌کند');
        $this->assertNull($shipment->{Shipment::WAYBILL_REF});
        $this->assertEquals($balanceBefore, (float) $this->buyerWallet->fresh()->balance,
            'بند ۳.۷: هیچ تسویه‌ای برنمی‌گردد چون اصلاً چیزی اجرا نشده بود');
    }

    #[Test]
    public function the_expiry_command_rejects_a_stale_request(): void
    {
        $this->triggerOverTolerance();

        // مهلت را به گذشته ببر تا کران بردارَدش.
        $this->order->forceFill([Order::OVERWEIGHT_DEADLINE => now()->subHour()])->save();

        $this->artisan('orders:expire-overweight')->assertSuccessful();

        $this->order->refresh();

        $this->assertSame(Order::STATUS_PROCESSING, $this->order->{Order::STATUS});
        $this->assertFalse($this->order->hasOpenOverweightRequest());
    }

    #[Test]
    public function a_request_still_within_its_deadline_is_left_alone(): void
    {
        $this->triggerOverTolerance();

        $this->artisan('orders:expire-overweight')->assertSuccessful();

        $this->order->refresh();

        $this->assertSame(Order::STATUS_AWAITING_WEIGHT_CORRECTION, $this->order->{Order::STATUS});
        $this->assertTrue($this->order->hasOpenOverweightRequest());
    }
}
