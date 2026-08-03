<?php

namespace Tests\Feature\Financial;

use App\Constants\FinancialCodes;
use App\Models\Order;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Services\Financial\LedgerReconciler;
use App\Services\Financial\WalletTransactionEngine;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * تطبیقِ دفترِ مالی با موجودیِ کیف‌ها.
 *
 * ارزشِ کلِ این ابزار به درست بودنِ «قاعدهٔ بازپخش» بند است — اگر قاعده حتی برای یک
 * ترکیبِ (نوع، وضعیت) غلط باشد، ابزار یا مغایرتِ کاذب می‌سازد (و بعد از چند بار
 * نادیده گرفته می‌شود) یا مغایرتِ واقعی را می‌بلعد. پس هر سطرِ آن جدول اینجا
 * جداگانه پین شده، نه فقط مسیرِ خوشحال.
 *
 * تست‌ها با `userId`/`orderId` محدود می‌شوند تا به دادهٔ باقی‌مانده در دیتابیسِ تست
 * وابسته نباشند.
 */
class LedgerReconcilerTest extends TestCase
{
    use DatabaseTransactions;

    private LedgerReconciler $reconciler;

    protected function setUp(): void
    {
        parent::setUp();
        $this->reconciler = app(LedgerReconciler::class);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // بازپخشِ کیف پول
    // ─────────────────────────────────────────────────────────────────────────

    #[Test]
    public function a_wallet_that_agrees_with_its_ledger_is_not_reported(): void
    {
        $wallet = Wallet::factory()->create();

        // یک ترکیبِ واقع‌گرایانه از همهٔ حالت‌های مؤثر:
        //   +۱۰۰۰ واریزِ تأییدشده، −۳۰۰ برداشتِ تأییدشده، +۵۰۰ هولد
        $this->ledgerRow($wallet, 1000, WalletTransaction::TYPE_DEPOSIT, WalletTransaction::STATUS_CONFIRMED);
        $this->ledgerRow($wallet, 300, WalletTransaction::TYPE_WITHDRAW, WalletTransaction::STATUS_CONFIRMED);
        $this->ledgerRow($wallet, 500, WalletTransaction::TYPE_DEPOSIT, WalletTransaction::STATUS_HOLD);

        $wallet->update([Wallet::BALANCE => 700, Wallet::BALANCE_HOLD => 500]);

        $this->assertSame([], $this->reconciler->driftedWallets($wallet->user_id));
    }

    #[Test]
    public function a_balance_the_ledger_cannot_explain_is_reported(): void
    {
        // دقیقاً همان الگویی که DevSeeder می‌سازد: موجودی بدون هیچ ردیفی در دفتر.
        // در محیطِ توسعه بی‌ضرر است، ولی همین شکل در پروداکشن یعنی پولِ خلق‌شده.
        $wallet = Wallet::factory()->create([Wallet::BALANCE => 500_000_000]);

        $drift = $this->reconciler->driftedWallets($wallet->user_id);

        $this->assertCount(1, $drift);
        $this->assertSame($wallet->id, $drift[0]['wallet_id']);
        $this->assertSame('500000000', $drift[0]['balance_diff']);
        $this->assertSame('0', $drift[0]['hold_diff']);
    }

    #[Test]
    public function restricting_to_one_user_does_not_leak_other_wallets(): void
    {
        // `financial:doctor --user=X` باید فقط کیفِ همان کاربر را برگرداند.
        //
        // شرطِ مغایرت یک `whereRaw` با `OR` است و فیلترِ کاربر با `AND` بعد از آن
        // اضافه می‌شود. بدون پرانتز، AND محکم‌تر از OR می‌چسبد و شرط عملاً می‌شود
        // «balance مغایر است **یا** (hold مغایر است و کاربر X است)» — یعنی هر کیفی
        // در کلِ سیستم که موجودی‌اش مغایر بود، در گزارشِ *یک کاربر* ظاهر می‌شد.
        // این تست دقیقاً همان نشت را می‌گیرد: هر دو کیف مغایرتِ `balance` دارند.
        $mine = Wallet::factory()->create([Wallet::BALANCE => 111]);
        $theirs = Wallet::factory()->create([Wallet::BALANCE => 222]);

        $drift = $this->reconciler->driftedWallets($mine->user_id);

        $this->assertCount(1, $drift);
        $this->assertSame($mine->id, $drift[0]['wallet_id']);
        $this->assertNotContains(
            $theirs->id,
            array_column($drift, 'wallet_id'),
            'کیف پول کاربرِ دیگر نباید در گزارشِ محدودشده ظاهر شود.'
        );
    }

    #[Test]
    public function a_pending_deposit_does_not_credit_the_balance(): void
    {
        // فیشِ کاربر تا تأییدِ ادمین هیچ اثری روی موجودی ندارد (FundWalletAction).
        $wallet = Wallet::factory()->create([Wallet::BALANCE => 0]);
        $this->ledgerRow($wallet, 9000, WalletTransaction::TYPE_DEPOSIT, WalletTransaction::STATUS_PENDING);

        $this->assertSame([], $this->reconciler->driftedWallets($wallet->user_id));
    }

    #[Test]
    public function a_pending_withdrawal_does_debit_the_balance(): void
    {
        // نقطهٔ مقابلِ تستِ بالا و ضدشهودی‌ترین سطرِ قاعده: RequestWithdrawalAction
        // پول را همان لحظه برمی‌دارد و ردیف را pending می‌گذارد؛ تسویهٔ بانکی فقط
        // وضعیت را عوض می‌کند. اگر این را «هنوز کسر نشده» فرض کنیم، هر کاربری که
        // درخواستِ برداشتِ در جریان دارد به‌غلط مغایر گزارش می‌شود.
        $wallet = Wallet::factory()->create([Wallet::BALANCE => 1000]);
        $this->ledgerRow($wallet, 10_000, WalletTransaction::TYPE_DEPOSIT, WalletTransaction::STATUS_CONFIRMED);
        $this->ledgerRow($wallet, 9000, WalletTransaction::TYPE_WITHDRAW, WalletTransaction::STATUS_PENDING);

        $this->assertSame([], $this->reconciler->driftedWallets($wallet->user_id));
    }

    #[Test]
    public function a_rejected_withdrawal_nets_to_zero(): void
    {
        // کسر شد، بعد ادمین رد کرد و پول برگشت — اثرِ خالص صفر است.
        $wallet = Wallet::factory()->create([Wallet::BALANCE => 0]);
        $this->ledgerRow($wallet, 4000, WalletTransaction::TYPE_WITHDRAW, WalletTransaction::STATUS_REJECTED);

        $this->assertSame([], $this->reconciler->driftedWallets($wallet->user_id));
    }

    #[Test]
    public function a_hold_release_moves_money_from_hold_to_balance(): void
    {
        $wallet = Wallet::factory()->create();
        $this->ledgerRow($wallet, 800, WalletTransaction::TYPE_DEPOSIT, WalletTransaction::STATUS_HOLD);
        $this->ledgerRow($wallet, 800, WalletTransaction::TYPE_HOLD_RELEASE, FinancialCodes::STATUS_AVAILABLE);

        $wallet->update([Wallet::BALANCE => 800, Wallet::BALANCE_HOLD => 0]);

        $this->assertSame([], $this->reconciler->driftedWallets($wallet->user_id));
    }

    #[Test]
    public function a_hold_that_was_never_released_keeps_the_money_in_hold(): void
    {
        $wallet = Wallet::factory()->create([Wallet::BALANCE => 0, Wallet::BALANCE_HOLD => 0]);
        $this->ledgerRow($wallet, 800, WalletTransaction::TYPE_DEPOSIT, WalletTransaction::STATUS_HOLD);

        // هولد ثبت شده ولی ستون balance_hold صفر مانده → باید مغایرت گزارش شود.
        $drift = $this->reconciler->driftedWallets($wallet->user_id);

        $this->assertCount(1, $drift);
        $this->assertSame('-800', $drift[0]['hold_diff']);
    }

    #[Test]
    public function the_real_engine_leaves_the_wallet_in_agreement_with_the_ledger(): void
    {
        // مسیرِ واقعیِ تولید، نه ردیف‌های دستی: اگر روزی موتور جوری تغییر کند که
        // موجودی و دفتر از هم جدا شوند، این تست می‌شکند.
        $buyer = User::factory()->create();
        $order = Order::factory()->create([Order::PLACER_USER_ID => $buyer->id]);

        $engine = app(WalletTransactionEngine::class);
        $engine->creditBuyer($order, 1_000_000, FinancialCodes::STATUS_AVAILABLE, 'شارژ');
        $engine->debitBuyer($order, 250_000, FinancialCodes::STATUS_AVAILABLE, 'خرید');

        $this->assertSame([], $this->reconciler->driftedWallets($buyer->id));
        $this->assertSame(750_000, (int) $buyer->wallet()->first()->balance);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // صفر شدنِ جمعِ جبریِ سفارش
    // ─────────────────────────────────────────────────────────────────────────

    #[Test]
    public function the_real_engine_produces_an_order_that_nets_to_zero(): void
    {
        $buyer = User::factory()->create();
        // کیف از قبل شارژ شده (خارج از دفتر) تا پرداختِ زیر رد شود — این تست
        // دربارهٔ تراز شدنِ *سفارش* است، نه شارژِ اولیه.
        Wallet::factory()->create([Wallet::USER_ID => $buyer->id, Wallet::BALANCE => 5_000_000]);

        $order = Order::factory()->create([Order::PLACER_USER_ID => $buyer->id]);
        $this->useSystemWallet();

        // همان جفتی که ProcessPreScalePaymentAction می‌سازد: WO(−POO) → WSSG(+POO).
        $engine = app(WalletTransactionEngine::class);
        $engine->debitBuyer($order, 5_000_000, FinancialCodes::STATUS_AVAILABLE, 'پرداخت سفارش');
        $engine->creditSystem($order, 5_000_000, FinancialCodes::STATUS_AVAILABLE, 'دریافت پلتفرم');

        $this->assertSame([], $this->reconciler->unbalancedOrders($order->id));
    }

    #[Test]
    public function an_unpaired_leg_written_by_the_engine_is_caught(): void
    {
        // اگر روزی یک مسیرِ جدید فقط یک طرفِ حرکت را بنویسد (باگی که در ممیزی‌ها
        // بارها نزدیک بود رخ دهد)، سفارش دیگر به صفر نمی‌رسد و اینجا لو می‌رود.
        $buyer = User::factory()->create();
        Wallet::factory()->create([Wallet::USER_ID => $buyer->id, Wallet::BALANCE => 5_000_000]);

        $order = Order::factory()->create([Order::PLACER_USER_ID => $buyer->id]);

        $engine = app(WalletTransactionEngine::class);
        $engine->debitBuyer($order, 5_000_000, FinancialCodes::STATUS_AVAILABLE, 'پرداخت بدون طرفِ مقابل');

        $unbalanced = $this->reconciler->unbalancedOrders($order->id);

        $this->assertCount(1, $unbalanced);
        $this->assertSame(-5_000_000, (int) $unbalanced[0]['net']);
    }

    #[Test]
    public function a_one_sided_debit_is_reported_as_money_destroyed(): void
    {
        $order = Order::factory()->create();
        $wallet = Wallet::factory()->create([Wallet::BALANCE => 0]);

        // بدهکاری بدونِ بستانکاریِ متناظر — پول از سیستم خارج شد و به هیچ کیفی نرفت.
        $this->ledgerRow($wallet, 3_000_000, WalletTransaction::TYPE_WITHDRAW, WalletTransaction::STATUS_CONFIRMED, $order);

        $unbalanced = $this->reconciler->unbalancedOrders($order->id);

        $this->assertCount(1, $unbalanced);
        $this->assertSame($order->id, $unbalanced[0]['order_id']);
        $this->assertSame($order->order_number, $unbalanced[0]['order_number']);
        $this->assertSame(-3_000_000, (int) $unbalanced[0]['net']);
    }

    #[Test]
    public function paired_legs_of_a_settlement_net_to_zero(): void
    {
        $order = Order::factory()->create();
        $buyerWallet = Wallet::factory()->create();
        $supplierWallet = Wallet::factory()->create();

        // خریدار ۱۰ میلیون می‌دهد، تأمین‌کننده ۹ و پلتفرم ۱ می‌گیرد → خالص صفر.
        $this->ledgerRow($buyerWallet, 10_000_000, WalletTransaction::TYPE_WITHDRAW, WalletTransaction::STATUS_CONFIRMED, $order);
        $this->ledgerRow($supplierWallet, 9_000_000, WalletTransaction::TYPE_DEPOSIT, WalletTransaction::STATUS_CONFIRMED, $order);
        $this->ledgerRow($supplierWallet, 1_000_000, WalletTransaction::TYPE_DEPOSIT, WalletTransaction::STATUS_CONFIRMED, $order);

        $this->assertSame([], $this->reconciler->unbalancedOrders($order->id));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // هولدهای یتیم
    // ─────────────────────────────────────────────────────────────────────────

    #[Test]
    public function a_completed_order_with_an_unreleased_hold_is_reported(): void
    {
        $order = Order::factory()->create([Order::STATUS => Order::STATUS_COMPLETED]);
        $wallet = Wallet::factory()->create();

        $this->ledgerRow($wallet, 2_000_000, WalletTransaction::TYPE_DEPOSIT, WalletTransaction::STATUS_HOLD, $order);

        $orphans = $this->reconciler->orphanHolds($order->id);

        $this->assertCount(1, $orphans);
        $this->assertSame(Order::STATUS_COMPLETED, $orphans[0]['order_status']);
        $this->assertSame(2_000_000, (int) $orphans[0]['stuck_hold']);
    }

    #[Test]
    public function a_hold_that_was_released_leaves_no_orphan(): void
    {
        $order = Order::factory()->create([Order::STATUS => Order::STATUS_COMPLETED]);
        $wallet = Wallet::factory()->create();

        $this->ledgerRow($wallet, 2_000_000, WalletTransaction::TYPE_DEPOSIT, WalletTransaction::STATUS_HOLD, $order);
        $this->ledgerRow($wallet, 2_000_000, WalletTransaction::TYPE_HOLD_RELEASE, FinancialCodes::STATUS_AVAILABLE, $order);

        $this->assertSame([], $this->reconciler->orphanHolds($order->id));
    }

    #[Test]
    public function a_hold_on_an_in_flight_order_is_not_an_orphan(): void
    {
        // سفارشِ در جریان قرار است هولد داشته باشد — گزارشِ آن فقط نویز می‌سازد.
        $order = Order::factory()->create([Order::STATUS => Order::STATUS_PREPARING]);
        $wallet = Wallet::factory()->create();

        $this->ledgerRow($wallet, 2_000_000, WalletTransaction::TYPE_DEPOSIT, WalletTransaction::STATUS_HOLD, $order);

        $this->assertSame([], $this->reconciler->orphanHolds($order->id));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // گاردِ قاعده
    // ─────────────────────────────────────────────────────────────────────────

    #[Test]
    public function an_unmodelled_type_is_reported_instead_of_being_silently_ignored(): void
    {
        // `fee_hold` امروز توسط هیچ کدی ساخته نمی‌شود ولی ثابتش هنوز هست. اگر
        // برگردد، شاخهٔ ELSE 0 آن را بی‌صدا صفر می‌گرفت و ابزار «سالم» می‌گفت در
        // حالی که پولِ آن ردیف اصلاً شمرده نشده بود.
        $wallet = Wallet::factory()->create();
        $this->ledgerRow($wallet, 1_000, WalletTransaction::TYPE_FEE_HOLD, WalletTransaction::STATUS_CONFIRMED);

        $unknown = collect($this->reconciler->unknownCombinations())
            ->firstWhere('type', WalletTransaction::TYPE_FEE_HOLD);

        $this->assertNotNull($unknown, 'ترکیبِ مدل‌نشده باید گزارش شود.');
        $this->assertSame(WalletTransaction::STATUS_CONFIRMED, $unknown['status']);
    }

    #[Test]
    public function the_full_report_is_healthy_when_nothing_is_wrong(): void
    {
        $wallet = Wallet::factory()->create([Wallet::BALANCE => 1_500]);
        $this->ledgerRow($wallet, 1_500, WalletTransaction::TYPE_DEPOSIT, WalletTransaction::STATUS_CONFIRMED);

        $report = $this->reconciler->report(userId: $wallet->user_id);

        $this->assertSame([], $report['wallets']['drifted']);
        $this->assertSame([], $report['problems']);
        $this->assertTrue($report['healthy']);
    }

    // ─────────────────────────────────────────────────────────────────────────

    /**
     * کیفِ سیستم (WSSG) را به یک کاربرِ واقعیِ همین تست وصل می‌کند.
     *
     * پیش‌فرضِ `financial.system_user_id` عدد ۱ است و در دیتابیسِ تست چنین کاربری
     * وجود ندارد؛ بدون این، creditSystem روی قید کلید خارجیِ `wallets.user_id`
     * می‌شکند. همان الگوی PayHoldFeeActionTest.
     */
    private function useSystemWallet(): User
    {
        $systemUser = User::factory()->create();
        config(['financial.system_user_id' => $systemUser->id]);

        return $systemUser;
    }

    private function ledgerRow(Wallet $wallet, float $amount, string $type, string $status, ?Order $order = null): WalletTransaction
    {
        return WalletTransaction::factory()->create([
            WalletTransaction::WALLET_ID => $wallet->id,
            WalletTransaction::ORDER_ID => $order?->id,
            WalletTransaction::AMOUNT => $amount,
            WalletTransaction::TYPE => $type,
            WalletTransaction::STATUS => $status,
        ]);
    }
}
