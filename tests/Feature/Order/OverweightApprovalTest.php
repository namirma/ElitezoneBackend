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
 * جریان تأیید دومرحله‌ای اضافه‌بار — بند ۳.۷ و ۸.۲ گام ۷الف سند.
 *
 * ⚠️ **این تست یک بن‌بستِ واقعی را قفل می‌کند.** پیش از فاز اصلاح، سفارشی که
 * اضافه‌بارش از تلورانس می‌گذشت به `awaiting_weight_correction` می‌رفت و هیچ
 * مسیری برای خروج نداشت: نه اکشنی، نه روتی، و `$weighableStatuses` هم باسکولِ
 * دوباره را رد می‌کرد. `a_stuck_order_can_be_resolved…` دقیقاً همان را می‌سنجد
 * و اگر کسی گاردها را برگرداند، قرمز می‌شود.
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
            ->shouldReceive('generateWeighbridgeDocs')->andReturnNull();
        $this->mock(SmsService::class)
            ->shouldReceive('send')->andReturn(true);

        $this->action = app(RequestOverweightApprovalAction::class);
        $this->weighbridge = app(ProcessWeighbridgeResultAction::class);

        $systemUser = User::factory()->create();
        config(['financial.system_user_id' => $systemUser->id]);
        config(['financial.vat_user_id' => $systemUser->id]);

        $badge = Badge::firstOrCreate([Badge::CODE => 'pioneer'], [Badge::NAME => 'Pioneer']);

        $this->buyer = User::factory()->create([User::BADGE_ID => $badge->id]);
        // موجودیِ فراوان: این تست دربارهٔ **جریان تأیید** است، نه دربارهٔ
        // کسریِ کیف پول. با موجودی کم، شاخهٔ بدهی فعال می‌شد و ادعای تست عوض.
        $this->buyerWallet = Wallet::create([
            Wallet::USER_ID => $this->buyer->id,
            Wallet::BALANCE => 500_000_000,
            Wallet::BALANCE_HOLD => 0,
        ]);

        $this->supplier = User::factory()->create();
        Wallet::create([Wallet::USER_ID => $this->supplier->id, Wallet::BALANCE => 1_000_000, Wallet::BALANCE_HOLD => 0]);

        $calc = app(SettlementCalculatorService::class);
        $rates = $calc->getCommissionRates('pioneer');
        $vat = 10.0;
        $poo = $calc->calculatePOO(0, 1000, 10, $rates['ow_final_rate'], $vat, true, true);
        $pts = $calc->calculatePTS(0, 1000, 10, $rates['ow_final_rate'], $rates['ssg_final_rate'], $rates['co_final_rate'], $vat, true, true);

        Wallet::create([Wallet::USER_ID => $systemUser->id, Wallet::BALANCE => $poo - $pts, Wallet::BALANCE_HOLD => 0]);

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
    }

    /** وزنِ ۲۰ در برابر WBS=۱۰ یعنی ۱۰۰٪ اضافه‌بار — بسیار فراتر از تلورانس ۲۰٪. */
    private function halt(): void
    {
        $this->weighbridge->execute($this->order, 20.0);
        $this->order->refresh();
    }

    #[Test]
    public function exceeding_the_tolerance_opens_a_request_with_an_amount_and_a_deadline(): void
    {
        $this->halt();

        $this->assertSame(Order::STATUS_AWAITING_WEIGHT_CORRECTION, $this->order->{Order::STATUS});
        $this->assertTrue($this->order->hasOpenOverweightRequest());
        $this->assertGreaterThan(0, (float) $this->order->{Order::OVERWEIGHT_PENDING_AMOUNT});
        $this->assertNotNull($this->order->{Order::OVERWEIGHT_DEADLINE});
        $this->assertNull($this->order->{Order::WEIGHING_COMPLETED_AT},
            'تا پیش از تأیید دو طرف هیچ تسویه‌ای نباید ثبت شود');
    }

    #[Test]
    public function the_buyer_cannot_accept_before_the_supplier_has_approved(): void
    {
        $this->halt();

        $this->expectException(BusinessException::class);
        $this->action->approveByBuyer($this->order);
    }

    /**
     * ‏**قلبِ این فایل.** پیش از اصلاح، این تست غیرممکن بود: تأییدی وجود نداشت
     * و حتی اگر وضعیت را دستی عوض می‌کردی، گاردِ `$weighableStatuses` باسکول را
     * رد می‌کرد.
     */
    #[Test]
    public function a_stuck_order_can_be_resolved_by_both_parties_approving(): void
    {
        $this->halt();
        $balanceBefore = (float) $this->buyerWallet->fresh()->balance;

        $this->action->approveBySupplier($this->order);
        $this->order->refresh();
        $this->assertNotNull($this->order->{Order::OVERWEIGHT_SUPPLIER_APPROVED_AT});
        $this->assertFalse($this->order->isOverweightApproved(), 'یک مهر کافی نیست');

        $result = $this->action->approveByBuyer($this->order->refresh());
        $this->order->refresh();

        $this->assertTrue($this->order->isOverweightApproved());
        $this->assertNotSame(Order::STATUS_AWAITING_WEIGHT_CORRECTION, $this->order->{Order::STATUS},
            'سفارش باید از بن‌بست خارج شده باشد');
        $this->assertNotNull($this->order->{Order::WEIGHING_COMPLETED_AT}, 'تعدیل واقعاً اجرا شده');
        $this->assertSame('surplus', $result['status']);
        $this->assertLessThan($balanceBefore, (float) $this->buyerWallet->fresh()->balance,
            'مابه‌التفاوت پس از پذیرش از کیف پول خریدار کسر می‌شود');
        $this->assertEquals(0.0, (float) $this->order->{Order::OVERWEIGHT_PENDING_AMOUNT},
            'درخواست با اجرای تعدیل بسته می‌شود');
    }

    #[Test]
    public function rejecting_voids_the_waybill_and_returns_the_order_to_processing(): void
    {
        $this->halt();

        $shipment = Shipment::create([
            Shipment::ORDER_ID => $this->order->id,
            Shipment::WEIGHT => 10,
            Shipment::SHIPPING_COST => 0,
            Shipment::STATUS => Shipment::STATUS_SHIPPED,
        ]);
        $shipment->forceFill([
            Shipment::WAYBILL_REF => 'WB-'.Str::random(5),
            Shipment::ACTUAL_WEIGHT => 20,
            Shipment::IS_FINAL => true,
            Shipment::REGISTERED_AT => now(),
        ])->save();

        $balanceBefore = (float) $this->buyerWallet->fresh()->balance;

        $this->action->reject($this->order, 'buyer', 'وزن اشتباه است');
        $this->order->refresh();
        $shipment->refresh();

        $this->assertSame(Order::STATUS_PROCESSING, $this->order->{Order::STATUS});
        $this->assertNull($this->order->{Order::WEIGHT_AFTER_SCALE},
            'وزنی که هیچ‌کس نپذیرفته نباید روی سفارش بماند');
        $this->assertFalse($this->order->hasOpenOverweightRequest());
        $this->assertNull($shipment->{Shipment::WAYBILL_REF}, 'بارنامه باطل می‌شود');
        $this->assertNull($shipment->{Shipment::REGISTERED_AT});
        $this->assertFalse((bool) $shipment->{Shipment::IS_FINAL});
        $this->assertEquals($balanceBefore, (float) $this->buyerWallet->fresh()->balance,
            'بند ۳.۷: هیچ تسویه‌ای برنمی‌گردد چون اصلاً اجرا نشده بود');
    }

    #[Test]
    public function the_expiry_command_rejects_a_request_nobody_answered(): void
    {
        $this->halt();

        $this->order->forceFill([Order::OVERWEIGHT_DEADLINE => now()->subHour()])->save();

        $this->artisan('orders:expire-overweight')->assertExitCode(0);

        $this->order->refresh();
        $this->assertSame(Order::STATUS_PROCESSING, $this->order->{Order::STATUS});
        $this->assertFalse($this->order->hasOpenOverweightRequest());
    }

    #[Test]
    public function a_request_that_is_still_within_its_deadline_survives_the_cron(): void
    {
        $this->halt();

        $this->artisan('orders:expire-overweight')->assertExitCode(0);

        $this->order->refresh();
        $this->assertSame(Order::STATUS_AWAITING_WEIGHT_CORRECTION, $this->order->{Order::STATUS});
        $this->assertTrue($this->order->hasOpenOverweightRequest());
    }

    #[Test]
    public function approving_an_order_with_no_open_request_is_refused(): void
    {
        $this->expectException(BusinessException::class);
        $this->action->approveBySupplier($this->order);
    }

    /**
     * شاخه‌ی دومِ همان بن‌بست (بند ۸.۲ گام ۷ب): اضافه‌بارِ **درون** تلورانس که
     * خریدار پولش را ندارد. لِگ‌های مالی اجرا می‌شوند و بدهی ثبت می‌شود، ولی
     * بار متوقف می‌ماند — و پیش از این هیچ راهی برای آزادکردنش نبود.
     */
    #[Test]
    public function a_buyer_who_could_not_cover_the_overweight_can_pay_and_release_the_load(): void
    {
        $this->buyerWallet->update([Wallet::BALANCE => 0]);

        $result = $this->weighbridge->execute($this->order, 11.0);
        $this->order->refresh();

        $this->assertSame('surplus_pending_buyer', $result['status']);
        $this->assertSame(Order::STATUS_AWAITING_WEIGHT_CORRECTION, $this->order->{Order::STATUS});

        $debt = app(\App\Services\Financial\DebtEngine::class)->balanceFor((int) $this->buyer->id);
        $this->assertGreaterThan(0, $debt);

        // خریدار کیفش را شارژ می‌کند و بدهی را می‌پردازد.
        $this->buyerWallet->update([Wallet::BALANCE => $debt + 1_000_000]);

        $this->action->settleShortfall($this->order->refresh());
        $this->order->refresh();

        $this->assertSame(Order::STATUS_SHIPPED, $this->order->{Order::STATUS},
            'با تسویه‌ی بدهی، بار باید آزاد شود');
        $this->assertEquals(0.0, app(\App\Services\Financial\DebtEngine::class)->balanceFor((int) $this->buyer->id));
    }

    #[Test]
    public function settling_a_shortfall_that_does_not_exist_is_refused(): void
    {
        $this->expectException(BusinessException::class);
        $this->action->settleShortfall($this->order);
    }
}
