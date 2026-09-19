<?php

declare(strict_types=1);

namespace Tests\Feature\Financial;

use App\Actions\Financial\ProcessPreScalePaymentAction;
use App\Actions\Financial\ProcessWeighbridgeResultAction;
use App\Events\Financial\TransactionProcessed;
use App\Events\Order\OrderPaid;
use App\Jobs\Financial\CheckSupplierBalancesJob;
use App\Models\Coupon;
use App\Models\CouponFunder;
use App\Models\CouponFundingLeg;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Services\Financial\SettlementCalculatorService;
use App\Services\Financial\SupplierDebtHandler;
use App\Services\Invoice\InvoiceGenerator;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * پولِ کوپن در باسکول باید با تخفیفِ ثبت‌شده بخواند.
 *
 * باگی که این تست پین می‌کند: باسکول `coupon_discount_amount` تازه‌ای می‌نوشت ولی
 * هیچ لِگ WSSG↔WO برایش ثبت نمی‌کرد. کوپنِ ۵٪ روی ۱۰۰ میلیون، نیمی تحویل: خریدار
 * کلِ ۵۰ میلیون مابه‌التفاوت را پس می‌گرفت و کلِ ۵ میلیون تخفیف را هم نگه می‌داشت،
 * در حالی که سفارش ۲٫۵ میلیون ثبت می‌کرد — WSSG ۲٫۵ میلیون ضرر. اضافه‌بار برعکس.
 *
 * ثابتِ همه‌ی تست‌ها: پس از تسویه، خریدار دقیقاً «POO نهایی − تخفیفِ نهایی» پرداخته
 * و `financial:doctor` سالم است. کیف سازه **بدونِ شناوری** شروع می‌کند تا ترتیبِ
 * «WSSG هرگز منفی نمی‌شود» واقعاً سنجیده شود.
 */
class WeighbridgeCouponSettlementTest extends TestCase
{
    use DatabaseTransactions;

    private const OP = 100_000;

    private const WBS = 1_000.0;

    private const BUYER_FUNDS = 300_000_000;

    private User $system;

    private User $buyer;

    private User $supplier;

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
        $this->ledger($this->buyer, self::BUYER_FUNDS, WalletTransaction::TYPE_DEPOSIT);
        $this->supplier = User::factory()->create();
        Wallet::create([Wallet::USER_ID => $this->supplier->id, Wallet::BALANCE => 0, Wallet::BALANCE_HOLD => 0]);
    }

    #[Test]
    public function under_delivery_returns_the_excess_discount_netted_against_the_refund(): void
    {
        [$order, $discount] = $this->paidOrderWithCoupon(Coupon::TYPE_PERCENT);
        $oldPOO = (float) $order->payment_of_owner;

        $result = app(ProcessWeighbridgeResultAction::class)->execute($order, self::WBS / 2);
        $order->refresh();

        $this->assertSame('deficit', $result['status']);
        $this->assertEqualsWithDelta($discount * (float) $order->payment_of_owner / $oldPOO, (float) $order->coupon_discount_amount, 1.0);
        $this->assertLessThan($discount, (float) $order->coupon_discount_amount);
        $this->assertBuyerPaidTheFinalInvoice($order);
        $this->assertLedgerIsHealthy($order);
    }

    #[Test]
    public function over_delivery_grants_the_extra_discount_netted_against_the_charge(): void
    {
        [$order, $discount] = $this->paidOrderWithCoupon(Coupon::TYPE_PERCENT);

        $result = app(ProcessWeighbridgeResultAction::class)->execute($order, self::WBS * 1.1);
        $order->refresh();

        $this->assertSame('surplus', $result['status']);
        $this->assertGreaterThan($discount, (float) $order->coupon_discount_amount);
        $this->assertBuyerPaidTheFinalInvoice($order);
        $this->assertLedgerIsHealthy($order);
    }

    /**
     * فرضِ صریح (کوچک‌ترین نسخه‌ی امن): پارک هیچ پولی جابه‌جا نمی‌کند و برگشت‌دادنِ سهمِ
     * تأمین‌کنندگان منبعی ندارد، پس تخفیف بازمحاسبه نمی‌شود — ولی عددِ ثبت‌شده با پول می‌خواند.
     */
    #[Test]
    public function a_parked_deficit_keeps_the_discount_it_paid_and_stays_balanced(): void
    {
        [$order, $discount] = $this->paidOrderWithCoupon(Coupon::TYPE_PERCENT);

        // تأمین‌کننده PTS را برداشت کرده — مسیرِ پارک.
        $supplierBalance = (int) Wallet::where(Wallet::USER_ID, $this->supplier->id)->value(Wallet::BALANCE);
        $this->ledger($this->supplier, $supplierBalance, WalletTransaction::TYPE_WITHDRAW);

        // ⚠️ مثل تستِ کوپنِ ثابت: تخفیف کامل می‌ماند ولی کمیسیونِ سازه نصف می‌شود، پس
        // WSSG بدونِ شناوری عودتِ آزادشده در کرون را نمی‌تواند بدهد (همان جریانِ پولِ پیش
        // از این تغییر). ردیفِ دفتر دارد تا doctor مغایرت نبیند.
        $this->ledger($this->system, 10_000_000, WalletTransaction::TYPE_DEPOSIT);

        $result = app(ProcessWeighbridgeResultAction::class)->execute($order, self::WBS / 2);
        $order->refresh();

        $this->assertSame('deficit_pending_supplier', $result['status']);
        $this->assertEqualsWithDelta($discount, (float) $order->coupon_discount_amount, 0.01);
        $this->assertEqualsWithDelta((float) $result['financial_diff'], (float) $order->{Order::BUYER_REFUND_PENDING_AMOUNT}, 1.0);

        // تأمین‌کننده شارژ می‌کند و کرون هر دو لِگ را اجرا می‌کند.
        $this->ledger($this->supplier, $supplierBalance, WalletTransaction::TYPE_DEPOSIT);
        app(CheckSupplierBalancesJob::class)->handle(app(SupplierDebtHandler::class));

        $this->assertBuyerPaidTheFinalInvoice($order->refresh());
        $this->assertLedgerIsHealthy($order);
    }

    /** تأمینِ سه‌منبعی: اختلاف به **همان** تأمین‌کنندگانِ لِگ‌های پرداخت و به همان نسبت برمی‌گردد. */
    #[Test]
    public function under_delivery_returns_each_funders_share_to_the_same_wallet(): void
    {
        [$order, $discount] = $this->paidOrderWithCoupon(Coupon::TYPE_PERCENT, supplierShare: 60);
        $supplierLegBefore = $this->legAmount($order, CouponFunder::TYPE_SUPPLIER);
        $oldPOO = (float) $order->payment_of_owner;

        $result = app(ProcessWeighbridgeResultAction::class)->execute($order, self::WBS / 2);
        $order->refresh();

        $this->assertSame('deficit', $result['status']);
        $this->assertLessThan($supplierLegBefore, $this->legAmount($order, CouponFunder::TYPE_SUPPLIER));
        $this->assertEqualsWithDelta($supplierLegBefore * (float) $order->payment_of_owner / $oldPOO,
            $this->legAmount($order, CouponFunder::TYPE_SUPPLIER), 2.0);
        $this->assertFundingLegsMatchTheRecordedDiscount($order);
        $this->assertBuyerPaidTheFinalInvoice($order);
        $this->assertLedgerIsHealthy($order);
    }

    #[Test]
    public function over_delivery_takes_each_funders_extra_share_from_the_same_wallet(): void
    {
        [$order, $discount] = $this->paidOrderWithCoupon(Coupon::TYPE_PERCENT, supplierShare: 60);
        $supplierLegBefore = $this->legAmount($order, CouponFunder::TYPE_SUPPLIER);

        $result = app(ProcessWeighbridgeResultAction::class)->execute($order, self::WBS * 1.1);
        $order->refresh();

        $this->assertSame('surplus', $result['status']);
        $this->assertGreaterThan($supplierLegBefore, $this->legAmount($order, CouponFunder::TYPE_SUPPLIER));
        $this->assertFundingLegsMatchTheRecordedDiscount($order);
        $this->assertBuyerPaidTheFinalInvoice($order);
        $this->assertLedgerIsHealthy($order);
    }

    /** یک کیف پولِ خالیِ تأمین‌کننده نباید کلِ باسکول را rollback کند: تخفیفِ اضافه داده نمی‌شود. */
    #[Test]
    public function an_extra_discount_a_funder_cannot_cover_is_not_granted(): void
    {
        $eliteYar = User::factory()->create();
        $this->ledger($eliteYar, 50_000_000, WalletTransaction::TYPE_DEPOSIT);

        [$order, $discount] = $this->paidOrderWithCoupon(Coupon::TYPE_PERCENT, eliteYar: $eliteYar);
        $this->ledger($eliteYar, (int) Wallet::where(Wallet::USER_ID, $eliteYar->id)->value(Wallet::BALANCE), WalletTransaction::TYPE_WITHDRAW);

        $result = app(ProcessWeighbridgeResultAction::class)->execute($order, self::WBS * 1.1);
        $order->refresh();

        $this->assertSame('surplus', $result['status']);
        $this->assertEqualsWithDelta($discount, (float) $order->coupon_discount_amount, 0.01);
        $this->assertFundingLegsMatchTheRecordedDiscount($order);
        $this->assertBuyerPaidTheFinalInvoice($order);
        $this->assertLedgerIsHealthy($order);
    }

    /** فرضِ صریح: کوپنِ مبلغ‌ثابت با وزن تغییر نمی‌کند و پولی هم بابتش جابه‌جا نمی‌شود. */
    #[Test]
    public function a_fixed_amount_coupon_is_not_recomputed_and_moves_no_extra_money(): void
    {
        [$order, $discount] = $this->paidOrderWithCoupon(Coupon::TYPE_FIXED);

        // ⚠️ فقط این تست به سازه شناوری می‌دهد. تخفیفِ ثابت کامل می‌ماند ولی کمیسیونِ
        // سازه با نیمه‌شدنِ بار نصف می‌شود، پس WSSG بدونِ شناوری عودت را نمی‌تواند
        // بدهد — رفتارِ پیش از این تغییر هم همین بود، چون برای کوپنِ ثابت پولی جابه‌جا
        // نمی‌شود. ردیفِ دفتر دارد تا doctor مغایرت نبیند.
        $this->ledger($this->system, 10_000_000, WalletTransaction::TYPE_DEPOSIT);

        $result = app(ProcessWeighbridgeResultAction::class)->execute($order, self::WBS / 2);
        $order->refresh();

        $this->assertSame('deficit', $result['status']);
        $this->assertEqualsWithDelta($discount, (float) $order->coupon_discount_amount, 0.01);
        $this->assertBuyerPaidTheFinalInvoice($order);
        $this->assertLedgerIsHealthy($order);
    }

    /**
     * سفارشی که واقعاً از مسیرِ `ProcessPreScalePaymentAction` با کوپن پرداخت شده.
     * تخفیف نصفِ کمیسیونِ سازه است تا WSSG بدونِ شناوری هم بتواند آن را بدهد.
     *
     * بدونِ `$supplierShare`/`$eliteYar` کوپن ردیفِ تأمین ندارد، یعنی شرکت ۱۰۰٪.
     *
     * @return array{0: Order, 1: float}
     */
    private function paidOrderWithCoupon(string $type, ?int $supplierShare = null, ?User $eliteYar = null): array
    {
        $calc = app(SettlementCalculatorService::class);
        $rates = $calc->getCommissionRatesForTc('pioneer', $calc->currentTcPercentage());
        $poo = $calc->calculatePOO(0, self::OP, self::WBS, $rates['ow_final_rate'], 10, true, true);
        $pts = $calc->calculatePTS(0, self::OP, self::WBS, $rates['ow_final_rate'], $rates['ssg_final_rate'], $rates['co_final_rate'], 10, true, true);
        $discount = floor(($poo - $pts) / 2);
        $this->assertGreaterThan(0, $discount, 'fixture: سازه باید کمیسیونی داشته باشد که تخفیف از آن بیاید.');

        $coupon = Coupon::create([
            Coupon::CODE => 'WCS-'.Str::random(10),
            Coupon::TYPE => $type,
            Coupon::AMOUNT => $type === Coupon::TYPE_PERCENT ? 5 : $discount,
            Coupon::IS_ACTIVE => true,
        ]);

        $funders = match (true) {
            $supplierShare !== null => [
                [CouponFunder::TYPE_SUPPLIER, $this->supplier->id, $supplierShare],
                [CouponFunder::TYPE_COMPANY, null, 100 - $supplierShare],
            ],
            $eliteYar !== null => [[CouponFunder::TYPE_ELITE_YAR, $eliteYar->id, 100]],
            default => [],
        };
        foreach ($funders as [$funderType, $userId, $share]) {
            CouponFunder::create([
                CouponFunder::COUPON_ID => $coupon->id,
                CouponFunder::FUNDER_TYPE => $funderType,
                CouponFunder::FUNDER_USER_ID => $userId,
                CouponFunder::SHARE_PERCENT => $share,
                CouponFunder::STATUS => CouponFunder::STATUS_ACCEPTED,
            ]);
        }

        $order = Order::create([
            Order::PLACER_USER_ID => $this->buyer->id,
            Order::PAYER_USER_ID => $this->buyer->id,
            Order::BENEFICIARY_USER_ID => $this->buyer->id,
            Order::ELITE_YAR_USER_ID => $eliteYar?->id,
            Order::ORDER_NUMBER => 'WCS-'.Str::random(10),
            Order::STATUS => Order::STATUS_PROCESSING,
            Order::PAYMENT_STATUS => Order::PAYMENT_PENDING,
            Order::WEIGHT_BEFORE_SCALE => self::WBS,
            Order::SHIPPING_COST => 0,
        ]);
        $order->forceFill([
            Order::OFFICIAL_PRICE => self::OP,
            Order::VAT_RATE => 10,
            Order::COUPON_ID => $coupon->id,
            Order::COUPON_DISCOUNT_AMOUNT => $discount,
        ])->save();
        OrderItem::factory()->create([
            OrderItem::ORDER_ID => $order->id,
            OrderItem::SUPPLIER_USER_ID => $this->supplier->id,
            OrderItem::UNIT_PRICE => self::OP,
            OrderItem::TOTAL_PRICE => self::OP * self::WBS,
            OrderItem::QUANTITY => self::WBS,
        ]);

        app(ProcessPreScalePaymentAction::class)->execute($order);

        // هر اختلافِ وزن رضایتِ دو طرف می‌خواهد؛ این تست درباره‌ی تسویه است، نه گارد.
        $order->refresh()->forceFill([
            Order::OVERWEIGHT_SUPPLIER_APPROVED_AT => now(),
            Order::OVERWEIGHT_BUYER_APPROVED_AT => now(),
        ])->save();

        return [$order->fresh(), $discount];
    }

    private function assertBuyerPaidTheFinalInvoice(Order $order): void
    {
        $this->assertEqualsWithDelta(
            self::BUYER_FUNDS - ((float) $order->payment_of_owner - (float) $order->coupon_discount_amount),
            (float) Wallet::where(Wallet::USER_ID, $this->buyer->id)->value(Wallet::BALANCE),
            1.0,
            'خریدار باید دقیقاً «POO نهایی − تخفیفِ ثبت‌شده» پرداخته باشد.'
        );
    }

    /** `reverseLegs()` در استردادِ بعدی جمعِ لِگ‌ها را برمی‌گرداند، پس باید همان تخفیفِ ثبت‌شده باشد. */
    private function assertFundingLegsMatchTheRecordedDiscount(Order $order): void
    {
        $this->assertEqualsWithDelta(
            (float) $order->coupon_discount_amount,
            (float) CouponFundingLeg::where(CouponFundingLeg::ORDER_ID, $order->id)->sum(CouponFundingLeg::AMOUNT),
            0.01,
        );
    }

    private function legAmount(Order $order, string $funderType): float
    {
        return (float) CouponFundingLeg::where(CouponFundingLeg::ORDER_ID, $order->id)
            ->where(CouponFundingLeg::FUNDER_TYPE, $funderType)
            ->value(CouponFundingLeg::AMOUNT);
    }

    private function assertLedgerIsHealthy(Order $order): void
    {
        $this->artisan('financial:doctor', ['--order' => $order->id])->assertExitCode(0);
    }

    /** یک ردیفِ دفتر بدونِ سفارش + همان تغییر روی موجودی، تا doctor مغایرتی نبیند. */
    private function ledger(User $user, int $amount, string $type): void
    {
        $wallet = Wallet::firstOrCreate([Wallet::USER_ID => $user->id], [Wallet::BALANCE => 0, Wallet::BALANCE_HOLD => 0]);
        $wallet->transactions()->create([
            WalletTransaction::AMOUNT => $amount,
            WalletTransaction::TYPE => $type,
            WalletTransaction::STATUS => WalletTransaction::STATUS_CONFIRMED,
            WalletTransaction::DESCRIPTION => 'fixture',
        ]);
        $delta = $type === WalletTransaction::TYPE_DEPOSIT ? $amount : -$amount;
        $wallet->forceFill([Wallet::BALANCE => (int) $wallet->balance + $delta])->save();
    }
}
