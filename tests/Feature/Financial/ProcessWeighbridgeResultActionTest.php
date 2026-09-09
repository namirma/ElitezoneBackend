<?php

namespace Tests\Feature\Financial;

use App\Actions\Financial\ProcessWeighbridgeResultAction;
use App\Constants\FinancialCodes;
use App\Models\Badge;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Services\Financial\DebtEngine;
use App\Services\Financial\SettlementCalculatorService;
use App\Services\Invoice\InvoiceGenerator;
use App\Services\SmsService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Tests for ProcessWeighbridgeResultAction — the post-weighbridge settlement.
 *
 * Focus: the spec invariant "final invoice ≤ initial invoice". Over-delivery
 * (WAS > WBS) must NEVER charge the buyer (billing weight is capped at WBS);
 * under-delivery (WAS < WBS) refunds the buyer the POO − POOM difference.
 *
 * DatabaseTransactions: writes roll back at teardown; the live DB is not mutated.
 */
class ProcessWeighbridgeResultActionTest extends TestCase
{
    use DatabaseTransactions;

    private ProcessWeighbridgeResultAction $action;

    private User $buyer;

    private Wallet $buyerWallet;

    private Wallet $systemWallet;

    private Order $order;

    private float $oldPOO;

    protected function setUp(): void
    {
        parent::setUp();

        // PDF generation is irrelevant to the financial assertions — mock it so the
        // missing test-environment Blade views don't break the run.
        $this->mock(InvoiceGenerator::class)
            ->shouldReceive('generateWeighbridgeDocs')
            ->andReturnNull();

        // SMS service has no TSMS credentials in test env — mock it so the constructor
        // does not throw TypeError (string property ← null config) and no real HTTP call
        // is made when the supplier-debt notification path is exercised.
        $this->mock(SmsService::class)
            ->shouldReceive('send')
            ->andReturn(true);

        $this->action = app(ProcessWeighbridgeResultAction::class);

        $systemUser = User::factory()->create();
        config(['financial.system_user_id' => $systemUser->id]);
        config(['financial.vat_user_id' => $systemUser->id]);
        // موجودی این کیف پول پایین‌تر در setUp و **دقیقاً برابر کمیسیونِ همین
        // سفارش** ست می‌شود (نه یک عدد دلبخواه) — توضیح کامل کنار همان خط.
        $this->systemWallet = Wallet::create([Wallet::USER_ID => $systemUser->id, Wallet::BALANCE => 0, Wallet::BALANCE_HOLD => 0]);

        // Pioneer badge on the buyer so beneficiary.badge->code resolves without a null read.
        // firstOrCreate: the 'pioneer' badge is now also seeded by a migration.
        $badge = Badge::firstOrCreate([Badge::CODE => 'pioneer'], [Badge::NAME => 'Pioneer']);

        $this->buyer = User::factory()->create([User::BADGE_ID => $badge->id]);
        $this->buyerWallet = Wallet::create([Wallet::USER_ID => $this->buyer->id, Wallet::BALANCE => 1000, Wallet::BALANCE_HOLD => 0]);

        $supplier = User::factory()->create();
        Wallet::create([Wallet::USER_ID => $supplier->id, Wallet::BALANCE => 1_000_000, Wallet::BALANCE_HOLD => 0]);

        // Pre-weighbridge snapshot for WBS = 10, OP = 1000, VAT 10%, no shipping.
        // Computed via the same calculator so the test isn't coupled to the rate table.
        $calc = app(SettlementCalculatorService::class);
        $rates = $calc->getCommissionRates('pioneer');

        // ⚠️ نرخ مالیات صریح است و صفر نیست. قبلاً این تست انتظاراتش را با
        // `$vat = 0` می‌ساخت و روی سفارش هم `VAT_RATE => 0` می‌گذاشت — یعنی
        // دقیقاً همان حالتی که باگِ `$order->vat_rate ?? 10.0` تولید می‌کرد
        // (ستون `NOT NULL DEFAULT 0.00` پس `??` هیچ‌وقت فعال نمی‌شد). تست روی
        // خودِ باگ بنا شده بود و نمی‌توانست آن را بگیرد.
        $vatPercent = 10.0;
        $this->oldPOO = $calc->calculatePOO(0, 1000, 10, $rates['ow_final_rate'], $vatPercent, true, true);
        $oldPTS = $calc->calculatePTS(0, 1000, 10, $rates['ow_final_rate'], $rates['ssg_final_rate'], $rates['co_final_rate'], $vatPercent, true, true);

        $this->order = Order::create([
            Order::PLACER_USER_ID => $this->buyer->id,
            Order::BENEFICIARY_USER_ID => $this->buyer->id, // self → no eliteYAR payout
            Order::ORDER_NUMBER => 'TEST-WB-'.Str::random(12),
            Order::STATUS => Order::STATUS_PROCESSING,
            Order::PAYMENT_STATUS => Order::PAYMENT_PAID,
            Order::WEIGHT_BEFORE_SCALE => 10,
            Order::SHIPPING_COST => 0,
        ]);
        $this->order->forceFill([
            Order::OFFICIAL_PRICE => 1000,
            Order::VAT_RATE => $vatPercent,
            Order::PAYMENT_OF_OWNER => $this->oldPOO,
            Order::PAYMENT_TO_SUPPLIER => $oldPTS,
            Order::TRANS_COMMISSION => true,
            Order::TRANS_VAT => true,
        ])->save();

        OrderItem::factory()->create([
            OrderItem::ORDER_ID => $this->order->id,
            OrderItem::SUPPLIER_USER_ID => $supplier->id,
            OrderItem::UNIT_PRICE => 1000,
            OrderItem::TOTAL_PRICE => 10000,
            OrderItem::QUANTITY => 10,
        ]);

        // ⚠️ کیف پول سازه **دقیقاً** کمیسیونِ همین سفارش را دارد (POO − PTS) —
        // یعنی همان چیزی که در تولید پس از پرداخت برایش می‌ماند: POO را گرفته و
        // بلافاصله PTS را پرداخته است.
        //
        // پیش‌تر اینجا یک ۱٬۰۰۰٬۰۰۰ ریالِ دلبخواه شارژ می‌شد «تا تسویه‌ی کسری
        // بتواند اجرا شود». آن عددِ سخاوتمندانه یک باگ واقعی را پنهان می‌کرد:
        // `SupplierDebtHandler` اول از سازه برداشت می‌کرد و بعد از سوپلایر پس
        // می‌گرفت، پس در محیط واقعی — که سازه فقط کمیسیون را دارد —
        // هر کم‌تحویلی با `InsufficientBalanceException` کلِ باسکول را rollback
        // می‌کرد. با این مقدارِ واقع‌بینانه، اگر کسی ترتیب legها را برگرداند
        // (عودت به خریدار پیش از بازپس‌گیری از سوپلایر) تست فوراً می‌شکند.
        $this->systemWallet->update([Wallet::BALANCE => $this->oldPOO - $oldPTS]);
    }

    // ─── سهم الیت‌یار ──────────────────────────────────────────
    //
    // ⚠️ فیکسچرِ setUp عمداً `beneficiary = buyer` و بدون `elite_yar_user_id`
    // است، پس `routeEliteYAR()` روی همان گاردِ اولش برمی‌گشت و **هیچ‌کدام** از
    // تست‌های این فایل شاخه‌ی پرداختِ کمیسیون را اجرا نمی‌کردند.
    //
    // نتیجه‌اش این بود که در کلِ مخزن هیچ تستی assert نمی‌کرد که
    // `PAYMENT_TO_ELITE_YAR` اصلاً **نوشته** می‌شود — تنها جای دیگرش یک
    // `forceFill` دستی در تستِ سقفِ مادام‌العمر بود. و همان ستون است که سقف،
    // پرداخت‌های قبلی را از رویش جمع می‌زند: اگر نوشتنش از کار می‌افتاد،
    // هر الیت‌یاری برای همیشه بی‌سقف می‌شد و هیچ‌چیز سرخ نمی‌شد.

    /**
     * هر دو رضایت را مهر می‌زند تا باسکول از گاردِ تازه رد شود.
     *
     * از ۲۰۲۶-۰۹-۰۹ (تصمیم مدیریت، بندِ ۲) **هر** اختلافِ وزن — چه اضافه، چه
     * کم — بدونِ رضایتِ خریدار و تأمین‌کننده متوقف می‌شود. تست‌هایی که نتیجه‌ی
     * **تسویه** را می‌سنجند باید اول از آن گارد رد شوند، وگرنه چیزی را
     * می‌سنجند که اصلاً اجرا نشده.
     */
    private function withBothApprovals(): void
    {
        $this->order->forceFill([
            Order::OVERWEIGHT_SUPPLIER_APPROVED_AT => now(),
            Order::OVERWEIGHT_BUYER_APPROVED_AT => now(),
        ])->save();
        $this->order->refresh();
    }

    #[Test]
    public function the_elite_yar_commission_is_written_to_the_order(): void
    {
        $eliteYar = $this->attachEliteYar();

        $this->action->execute($this->order, 10.0);

        $this->order->refresh();

        $gross = (float) $this->order->{Order::PAYMENT_TO_ELITE_YAR};
        $this->assertGreaterThan(
            0.0,
            $gross,
            'سهم الیت‌یار باید روی سفارش نوشته شود — سقف مادام‌العمر از همین ستون می‌خواند.'
        );

        // و پول واقعاً حرکت کرده باشد: کمیسیون امانی روی کیف پول الیت‌یار.
        $held = WalletTransaction::query()
            ->where(WalletTransaction::ORDER_ID, $this->order->id)
            ->where(WalletTransaction::META.'->code', FinancialCodes::WALLET_ELITE_YAR)
            ->where(WalletTransaction::STATUS, FinancialCodes::STATUS_HOLD)
            ->first();

        $this->assertNotNull($held, 'باید یک لِگِ امانیِ WEY ثبت شده باشد.');
        $this->assertSame(
            $eliteYar->id,
            (int) $held->wallet->user_id,
            'کمیسیون باید روی کیف پولِ خودِ الیت‌یار بنشیند.'
        );
    }

    #[Test]
    public function the_elite_yar_who_placed_the_order_is_still_paid_when_the_employer_pays(): void
    {
        // مسیرِ «الیت‌یار سبد را می‌بندد، کارفرما می‌پردازد» (فاز ۶). گاردِ
        // `routeEliteYAR()` عمداً با **پرداخت‌کننده** مقایسه می‌کند و نه ثبت‌کننده:
        // با `placer_user_id` این حالت همیشه فعال می‌شد — چون الیت‌یار خودش
        // ثبت‌کننده است — و کمیسیونِ همان مسیری را که فاز ۶ ممکن می‌کند بی‌صدا
        // صفر می‌کرد. هیچ تستی آن مرز را نگه نمی‌داشت.
        $employer = User::factory()->create();
        Wallet::create([Wallet::USER_ID => $employer->id, Wallet::BALANCE => 5_000_000, Wallet::BALANCE_HOLD => 0]);

        $this->order->forceFill([
            Order::PAYER_USER_ID => $employer->id,
            // الیت‌یار **همان ثبت‌کننده** است — دقیقاً حالتی که گاردِ غلط می‌گرفت.
            Order::ELITE_YAR_USER_ID => $this->buyer->id,
        ])->save();

        $this->action->execute($this->order, 10.0);

        $this->assertGreaterThan(
            0.0,
            (float) $this->order->refresh()->{Order::PAYMENT_TO_ELITE_YAR},
            'وقتی پرداخت‌کننده کارفرماست، الیت‌یارِ ثبت‌کننده باید کمیسیونش را بگیرد.'
        );
    }

    /** یک الیت‌یارِ متمایز از خریدار، با کیف پول، روی همین سفارش. */
    private function attachEliteYar(): User
    {
        $eliteYar = User::factory()->create();
        Wallet::create([Wallet::USER_ID => $eliteYar->id, Wallet::BALANCE => 0, Wallet::BALANCE_HOLD => 0]);

        $this->order->forceFill([Order::ELITE_YAR_USER_ID => $eliteYar->id])->save();

        return $eliteYar;
    }

    /**
     * ‏قبلاً «اضافه‌بار هرگز به خریدار صورت‌حساب نمی‌شود» را قفل می‌کرد. نسخه‌ی ۲
     * سند (بند ۸.۲) clamp را برداشت و دو شاخه‌ی تازه تعریف کرد؛ هر دو اینجا
     * قفل می‌شوند.
     */
    #[Test]
    public function over_delivery_beyond_the_tolerance_halts_without_moving_money(): void
    {
        $balanceBefore = (float) $this->buyerWallet->balance;

        // WAS (20) در برابر WBS (10) یعنی ۱۰۰٪ اضافه‌بار — بسیار فراتر از
        // تلورانس پیش‌فرض (۲۰٪).
        $result = $this->action->execute($this->order, 20.0);

        $this->buyerWallet->refresh();
        $this->order->refresh();

        $this->assertSame('awaiting_weight_correction', $result['status']);
        $this->assertEquals($balanceBefore, (float) $this->buyerWallet->balance,
            'فراتر از تلورانس هیچ لِگ مالی‌ای اجرا نمی‌شود');
        $this->assertSame(Order::STATUS_AWAITING_WEIGHT_CORRECTION, $this->order->{Order::STATUS});
        $this->assertNull($this->order->{Order::WEIGHING_COMPLETED_AT},
            'تسویه انجام نشده، پس نباید مهرِ اتمام بخورد');
        $this->assertEquals(20.0, (float) $this->order->weight_after_scale, 'وزن فیزیکی ثبت می‌شود');
    }

    #[Test]
    public function over_delivery_within_the_tolerance_charges_the_buyer(): void
    {
        // موجودیِ کافی برای مابه‌التفاوت. بدون آن، مسیرِ «کسری موجودی» فعال
        // می‌شود که تستِ بعدی آن را جدا می‌سنجد.
        $this->buyerWallet->update([Wallet::BALANCE => 100_000_000]);
        $balanceBefore = (float) $this->buyerWallet->fresh()->balance;

        // ۱۰٪ اضافه‌بار — درون تلورانس ۲۰٪.
        $this->withBothApprovals();

        $result = $this->action->execute($this->order, 11.0);

        $this->buyerWallet->refresh();
        $this->order->refresh();

        $this->assertSame('surplus', $result['status']);
        $this->assertLessThan($balanceBefore, (float) $this->buyerWallet->balance,
            'اضافه‌بارِ درون تلورانس از کیف پول خریدار کسر می‌شود');
        $this->assertGreaterThan($this->oldPOO, (float) $this->order->payment_of_owner);
        $this->assertSame(Order::STATUS_SHIPPED, $this->order->{Order::STATUS});
    }

    #[Test]
    public function an_overweight_the_buyer_cannot_cover_becomes_debt_and_halts_the_load(): void
    {
        // کیف پول خالی و سقف اعتبار صفر (پیش‌فرض): بدهی **ثبت می‌شود** و
        // سفارش هم متوقف می‌ماند — سند صریحاً هر دو را می‌خواهد، نه یکی.
        $this->buyerWallet->update([Wallet::BALANCE => 0]);

        $this->withBothApprovals();

        $result = $this->action->execute($this->order, 11.0);
        $this->order->refresh();

        $this->assertSame('surplus_pending_buyer', $result['status']);
        $this->assertSame(Order::STATUS_AWAITING_WEIGHT_CORRECTION, $this->order->{Order::STATUS});
        $this->assertGreaterThan(0, app(DebtEngine::class)->balanceFor((int) $this->buyer->id));
    }

    #[Test]
    public function a_credit_limit_absorbs_the_overweight_and_the_load_ships(): void
    {
        $this->buyerWallet->update([Wallet::BALANCE => 0]);
        $this->buyer->forceFill([User::CREDIT_LIMIT => 100_000_000])->save();

        $this->withBothApprovals();

        $result = $this->action->execute($this->order, 11.0);
        $this->order->refresh();

        $this->assertSame('surplus', $result['status']);
        $this->assertSame(Order::STATUS_SHIPPED, $this->order->{Order::STATUS});
        $this->assertGreaterThan(0, app(DebtEngine::class)->balanceFor((int) $this->buyer->id),
            'بدهی حتی درون سقف اعتبار هم ثبت می‌شود؛ سقف فقط جلوی توقف سفارش را می‌گیرد');
    }

    #[Test]
    public function under_delivery_refunds_the_buyer(): void
    {
        $balanceBefore = (float) $this->buyerWallet->balance;

        // WAS (5) < WBS (10): deficit → buyer is refunded the POO − POOM difference.
        $this->withBothApprovals();

        $result = $this->action->execute($this->order, 5.0);

        $this->buyerWallet->refresh();

        $this->assertSame('deficit', $result['status']);
        $this->assertGreaterThan($balanceBefore, (float) $this->buyerWallet->balance, 'Buyer must be refunded on under-delivery');
    }

    /**
     * قراردادِ اصلیِ تصمیم مدیریت ۲۰۲۶-۰۹-۰۹، بندِ ۶:
     * **مالیاتِ تأمین‌کننده «اصلاً نباید مسدود شود».**
     *
     * پیش از این، `WeighbridgeVatRouter::routeSupplierVat()` سهمِ مالیاتِ PTSM را
     * در کیفِ خودِ تأمین‌کننده از `balance` به `balance_hold` می‌برد و تا ثبتِ
     * فاکتور مودیان قابلِ برداشت نبود. حالا کلِ PTSM از همان لحظه در دسترسِ اوست.
     *
     * ⚠️ این تست هم‌زمان جلوی برگشتِ خاموش را می‌گیرد: اگر کسی روزی آن فراخوانی
     * را برگرداند، این‌جا قرمز می‌شود — نه در یک گزارشِ مالی سه ماه بعد.
     */
    #[Test]
    public function the_weighbridge_never_holds_the_suppliers_vat(): void
    {
        $supplierId = (int) $this->order->items()->value(OrderItem::SUPPLIER_USER_ID);
        $supplierWallet = Wallet::where(Wallet::USER_ID, $supplierId)->firstOrFail();

        $this->assertSame(
            0.0,
            (float) $supplierWallet->balance_hold,
            'پیش‌شرط: کیف پول تأمین‌کننده باید بدونِ هولد شروع کند.'
        );

        // باسکولِ برابر — ساده‌ترین مسیر، بدون کسری و بدون اضافه‌بار.
        $this->action->execute($this->order, 10.0);

        $supplierWallet->refresh();
        $this->order->refresh();

        $this->assertSame(
            0.0,
            (float) $supplierWallet->balance_hold,
            'مالیاتِ تأمین‌کننده نباید هولد شود — «اصلاً نباید مسدود شود».'
        );

        $this->assertNull(
            $this->order->{Order::SUPPLIER_VAT_PENDING_AMOUNT},
            'و چیزی هم نباید به‌عنوان مالیاتِ معلق پارک شود.'
        );

        // و باسکول واقعاً انجام شده — وگرنه ادعای بالا بی‌معنا بود.
        $this->assertNotNull(
            $this->order->{Order::WEIGHING_COMPLETED_AT},
            'باسکول باید نهایی شده باشد؛ وگرنه صفربودنِ هولد چیزی را ثابت نمی‌کند.'
        );
    }

    /**
     * گاردِ تازه — تصمیم مدیریت ۲۰۲۶-۰۹-۰۹، بندِ ۲.
     *
     * «درصد اضافه وزن مجاز یا کاهش وزن مجاز را حذف کنید» و به‌جایش **هر**
     * اختلافِ وزن رضایتِ خریدار و تأمین‌کننده می‌خواهد.
     *
     * دو چیز را با هم می‌سنجد که جداگانه بی‌معنا می‌شوند:
     *   • اختلافِ **کوچک** (۱٪ — بسیار زیرِ تلورانسِ قدیمِ ۲۰٪) دیگر خودکار
     *     تسویه نمی‌شود؛
     *   • و **کم‌تحویلی** هم متوقف می‌شود، که پیش از این هرگز از این گیت رد
     *     نمی‌شد چون `overweightPercent` علامت‌دار است و شرطِ قبلی `> tolerance`
     *     بود.
     */
    #[Test]
    public function any_weight_difference_now_halts_for_two_party_consent(): void
    {
        foreach ([10.1, 9.9] as $actualWeight) {
            $this->order->forceFill([
                Order::STATUS => Order::STATUS_PROCESSING,
                Order::WEIGHT_AFTER_SCALE => null,
                Order::WEIGHING_COMPLETED_AT => null,
                Order::OVERWEIGHT_SUPPLIER_APPROVED_AT => null,
                Order::OVERWEIGHT_BUYER_APPROVED_AT => null,
                Order::OVERWEIGHT_PENDING_AMOUNT => 0,
            ])->save();

            $balanceBefore = (float) $this->buyerWallet->fresh()->balance;

            $this->action->execute($this->order->refresh(), $actualWeight);
            $this->order->refresh();

            $this->assertSame(
                Order::STATUS_AWAITING_WEIGHT_CORRECTION,
                $this->order->{Order::STATUS},
                "اختلافِ وزن {$actualWeight} باید تا رضایتِ دو طرف متوقف بماند."
            );
            $this->assertNull(
                $this->order->{Order::WEIGHING_COMPLETED_AT},
                'باسکول نباید نهایی شده باشد.'
            );
            $this->assertSame(
                $balanceBefore,
                (float) $this->buyerWallet->fresh()->balance,
                'و هیچ پولی نباید جابه‌جا شده باشد.'
            );

            // مبلغِ درخواست باید **مثبت** ذخیره شود، حتی برای کم‌تحویلی —
            // وگرنه کرونِ انقضا و `hasOpenOverweightRequest()` نمی‌بینندش.
            $this->assertGreaterThan(
                0.0,
                (float) $this->order->{Order::OVERWEIGHT_PENDING_AMOUNT},
                "مبلغِ درخواست برای وزن {$actualWeight} باید مثبت باشد."
            );
            $this->assertTrue(
                $this->order->hasOpenOverweightRequest(),
                'درخواست باید «باز» شمرده شود.'
            );
        }
    }
}
