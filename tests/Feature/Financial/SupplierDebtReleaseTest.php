<?php

declare(strict_types=1);

namespace Tests\Feature\Financial;

use App\Constants\FinancialCodes;
use App\Jobs\Financial\CheckSupplierBalancesJob;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Services\Financial\DebtEngine;
use App\Services\Financial\LedgerReconciler;
use App\Services\Financial\WalletTransactionEngine;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * آزادسازیِ بدهیِ سوپلایر نباید دفترِ مالی را خراب کند.
 *
 * پیش‌زمینه: وقتی سوپلایر در لحظهٔ باسکول پول ندارد، عودتِ خریدار به‌صورت HOLD
 * پارک می‌شود و `CheckSupplierBalancesJob` بعداً آزادش می‌کند. آن job پس از
 * `releaseHold()` یک `update(['status' => AVAILABLE])` هم روی **ردیفِ اصلیِ هولد**
 * می‌زد تا دیگر «هولدِ باز» شمرده نشود.
 *
 * ولی `LedgerReconciler` موجودی را از روی همین ستون‌ها بازپخش می‌کند:
 *
 *   deposit + hold      → balance ۰    ، hold +مبلغ
 *   deposit + confirmed → balance +مبلغ ، hold ۰
 *   hold_release        → balance +مبلغ ، hold −مبلغ
 *
 * پس آن `update` ردیف را از سطرِ اول به سطرِ دوم می‌برد و بازپخش `+۲×مبلغ` روی
 * balance و `−مبلغ` روی hold می‌داد — در حالی که کیفِ واقعی `+۱×مبلغ` و `۰` دارد.
 * نتیجه: هر آزادسازیِ بدهی، کیف پول خریدار را **برای همیشه** در `financial:doctor`
 * مغایر می‌کرد، به اندازهٔ کلِ مبلغِ عودت.
 *
 * DatabaseTransactions: همهٔ نوشتن‌ها برمی‌گردند؛ دیتابیس زنده دست نمی‌خورد.
 */
class SupplierDebtReleaseTest extends TestCase
{
    use DatabaseTransactions;

    /** عودتِ خریدار (POO − POOM) — عمداً بزرگ‌تر از بدهیِ سوپلایر. */
    private const REFUND = 3_000_000.0;

    /** بازپس‌گیری از سوپلایر (PTS − PTSM). */
    private const DEBT = 2_000_000.0;

    private User $buyer;

    private User $supplier;

    private Order $order;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();

        $system = User::factory()->create();
        config(['financial.system_user_id' => $system->id]);
        Wallet::create([Wallet::USER_ID => $system->id, Wallet::BALANCE => 0, Wallet::BALANCE_HOLD => 0]);

        $this->buyer = User::factory()->create();
        Wallet::create([Wallet::USER_ID => $this->buyer->id, Wallet::BALANCE => 0, Wallet::BALANCE_HOLD => 0]);

        // سوپلایر حالا شارژ شده — دقیقاً شرطی که job منتظرش است. شارژ با یک ردیفِ
        // دفتر همراه می‌شود تا فیکسچر خودش مغایر نباشد؛ وگرنه ادعای «کیف می‌خواند»
        // به دقتِ محدودسازیِ گزارش تکیه می‌کرد، نه به درستیِ کد.
        $this->supplier = User::factory()->create();
        $supplierWallet = Wallet::create([
            Wallet::USER_ID => $this->supplier->id, Wallet::BALANCE => self::DEBT, Wallet::BALANCE_HOLD => 0,
        ]);
        $supplierWallet->transactions()->create([
            WalletTransaction::AMOUNT => self::DEBT,
            WalletTransaction::TYPE => WalletTransaction::TYPE_DEPOSIT,
            WalletTransaction::STATUS => WalletTransaction::STATUS_CONFIRMED,
            WalletTransaction::DESCRIPTION => 'شارژ اولیه سوپلایر',
        ]);

        $this->order = Order::create([
            Order::PLACER_USER_ID => $this->buyer->id,
            Order::ORDER_NUMBER => 'DEBT-'.Str::random(12),
            Order::STATUS => Order::STATUS_PROCESSING,
            Order::PAYMENT_STATUS => Order::PAYMENT_PAID,
        ]);

        OrderItem::factory()->create([
            OrderItem::ORDER_ID => $this->order->id,
            OrderItem::SUPPLIER_USER_ID => $this->supplier->id,
        ]);

        // همان کاری که SupplierDebtHandler::parkDebtAndNotify می‌کند: عودت در HOLD.
        app(WalletTransactionEngine::class)->creditBuyer(
            $this->order, self::REFUND, FinancialCodes::STATUS_HOLD, 'عودت پارک‌شده'
        );

        $this->order->forceFill([
            Order::SUPPLIER_DEBT_PENDING => true,
            Order::SUPPLIER_DEBT_AMOUNT => self::DEBT,
        ])->save();
    }

    private function buyerWallet(): Wallet
    {
        return Wallet::where(Wallet::USER_ID, $this->buyer->id)->firstOrFail();
    }

    private function runJob(): void
    {
        app(CheckSupplierBalancesJob::class)->handle(
            app(WalletTransactionEngine::class),
            app(DebtEngine::class),
        );
    }

    // ─────────────────────────────────────────────────────────────────────────

    #[Test]
    public function releasing_the_debt_leaves_the_buyer_wallet_reconcilable(): void
    {
        $this->runJob();

        $drift = app(LedgerReconciler::class)->driftedWallets($this->buyer->id);

        $this->assertSame(
            [],
            $drift,
            'بازپخشِ دفتر باید با موجودیِ ذخیره‌شده بخواند. اگر ردیفِ هولد به confirmed '
            .'تغییر داده شود، همان مبلغ دو بار شمرده می‌شود و این کیف تا ابد مغایر می‌ماند.'
        );
    }

    #[Test]
    public function the_full_refund_lands_as_available_balance(): void
    {
        $this->runJob();

        $wallet = $this->buyerWallet();

        // مبلغِ آزادشده باید کلِ عودت باشد، نه مبلغِ بدهی. این دو عمداً فرق دارند.
        $this->assertEqualsWithDelta(self::REFUND, (float) $wallet->balance, 0.5);
        $this->assertEqualsWithDelta(0.0, (float) $wallet->balance_hold, 0.5);
    }

    #[Test]
    public function the_original_hold_row_is_never_rewritten(): void
    {
        $this->runJob();

        // فقط کیفِ خریدار — سازه هم برای همین سفارش یک ردیفِ deposit دارد
        // (بازپس‌گیری از سوپلایر) و بدون این قید همان اول پیدا می‌شد.
        $hold = WalletTransaction::where('wallet_id', $this->buyerWallet()->id)
            ->where('order_id', $this->order->id)
            ->where('type', WalletTransaction::TYPE_DEPOSIT)
            ->firstOrFail();

        $this->assertSame(
            FinancialCodes::STATUS_HOLD,
            $hold->status,
            'ردیفِ دفتر یک رویدادِ گذشته است؛ آن رویداد «قرار دادنِ هولد» بود و بازنویسی‌اش تاریخ را عوض می‌کند.'
        );

        $release = WalletTransaction::where('wallet_id', $this->buyerWallet()->id)
            ->where('order_id', $this->order->id)
            ->where('type', WalletTransaction::TYPE_HOLD_RELEASE)
            ->firstOrFail();

        $this->assertSame($hold->id, (int) $release->meta['source_hold_id']);
    }

    #[Test]
    public function a_second_run_does_not_release_the_same_hold_twice(): void
    {
        $this->runJob();

        // پرچم پاک شده، ولی حتی با دستکاریِ دستیِ آن هم نباید دوباره آزاد شود:
        // ردیفِ هولد از طریق meta.source_hold_id به‌عنوان «آزادشده» شناخته می‌شود.
        $this->order->forceFill([
            Order::SUPPLIER_DEBT_PENDING => true,
            Order::SUPPLIER_DEBT_AMOUNT => self::DEBT,
        ])->save();

        Wallet::where(Wallet::USER_ID, $this->supplier->id)->update([Wallet::BALANCE => self::DEBT]);

        $this->runJob();

        $this->assertSame(
            1,
            WalletTransaction::where('order_id', $this->order->id)
                ->where('type', WalletTransaction::TYPE_HOLD_RELEASE)
                ->count(),
            'هولدِ آزادشده نباید دوباره پیدا شود.'
        );
    }

    #[Test]
    public function a_missing_buyer_hold_rolls_back_instead_of_clearing_the_debt_flag(): void
    {
        // حالتِ خراب: پرچم بدهی هست ولی هولدی برای آزادسازی نیست.
        WalletTransaction::where('order_id', $this->order->id)->delete();
        $this->buyerWallet()->update([Wallet::BALANCE_HOLD => 0]);

        $supplierBalanceBefore = (float) Wallet::where(Wallet::USER_ID, $this->supplier->id)->value(Wallet::BALANCE);

        $this->runJob(); // handle() استثنا را per-order می‌گیرد و لاگ می‌کند

        $this->assertTrue(
            (bool) $this->order->fresh()->{Order::SUPPLIER_DEBT_PENDING},
            'پرچم بدهی نباید پاک شود وقتی عودتِ خریدار آزاد نشده — وگرنه پولش برای همیشه قفل می‌ماند.'
        );

        $this->assertEqualsWithDelta(
            $supplierBalanceBefore,
            (float) Wallet::where(Wallet::USER_ID, $this->supplier->id)->value(Wallet::BALANCE),
            0.5,
            'بازپس‌گیری از سوپلایر باید با شکستِ آزادسازی برگردد — نه اینکه نیمه‌کاره بماند.'
        );
    }

    #[Test]
    public function a_legacy_pending_vat_is_cleared_without_ever_being_held(): void
    {
        // **قراردادِ عوض‌شده — تصمیم مدیریت ۲۰۲۶-۰۹-۰۹، بندِ ۶.**
        //
        // این تست پیش‌تر می‌سنجید که کرون مالیاتِ معلق را روی کیف پولِ خودِ
        // تأمین‌کننده هولد کند. حالا مالیات «اصلاً نباید مسدود شود»، پس:
        //
        //   • هیچ هولدی گذاشته نمی‌شود،
        //   • ولی یادداشتِ `supplier_vat_pending_amount` پاک می‌شود.
        //
        // ستون فقط یک **یادداشت** بود («این‌قدر بعداً باید هولد شود») و هرگز
        // پولی از کیف پول برنداشته بود؛ پس پاک‌کردنش چیزی از تأمین‌کننده کم
        // نمی‌کند. سفارش‌های قدیمی که این عدد را دارند از همین مسیر تمیز می‌شوند.
        $vat = 250_000.0;

        $this->order->forceFill([Order::SUPPLIER_VAT_PENDING_AMOUNT => $vat])->save();

        // موجودی فقط به‌اندازه‌ی **بدهی** — نه بدهی + مالیات. این خودش نیمی از
        // ادعاست: آستانه دیگر مالیات را حساب نمی‌کند.
        $supplierWallet = Wallet::where(Wallet::USER_ID, $this->supplier->id)->firstOrFail();
        $supplierWallet->update([
            Wallet::BALANCE => self::DEBT,
            Wallet::BALANCE_HOLD => 0,
        ]);

        $this->runJob();

        $supplierWallet->refresh();

        $this->assertEqualsWithDelta(
            0.0,
            (float) $supplierWallet->balance_hold,
            0.01,
            'مالیات دیگر هولد نمی‌شود — هیچ چیزی نباید در balance_hold بنشیند.'
        );

        $this->assertNull(
            $this->order->refresh()->{Order::SUPPLIER_VAT_PENDING_AMOUNT},
            'یادداشتِ مالیاتِ معلق باید پاک شود، حتی بدونِ هولد.'
        );
    }

    #[Test]
    public function a_balance_that_covers_only_the_debt_is_now_enough(): void
    {
        // **معکوسِ عمدیِ تستِ قبلی — و رفعِ یک باگ.**
        //
        // پیش از این آستانه `debt + vatPending` بود، چون هولدِ مالیات در همان
        // تراکنش می‌آمد و با موجودیِ ناکافی کلِ کار برمی‌گشت. حالا که هولدی در
        // کار نیست، نگه‌داشتنِ آن آستانه یک **باگ** بود: تأمین‌کننده‌ای که دقیقاً
        // به‌اندازه‌ی بدهی‌اش پول داشت، تا ابد تسویه نمی‌شد و منتظرِ پولی می‌ماند
        // که هیچ‌کس قرار نبود برداشتش کند.
        $vat = 250_000.0;

        $this->order->forceFill([Order::SUPPLIER_VAT_PENDING_AMOUNT => $vat])->save();

        $supplierWallet = Wallet::where(Wallet::USER_ID, $this->supplier->id)->firstOrFail();
        $supplierWallet->update([
            Wallet::BALANCE => self::DEBT,   // دقیقاً بدهی، بدونِ مالیات
            Wallet::BALANCE_HOLD => 0,
        ]);

        $this->runJob();

        $this->assertFalse(
            (bool) $this->order->refresh()->{Order::SUPPLIER_DEBT_PENDING},
            'با موجودیِ برابرِ بدهی، کسری باید تسویه شود — مالیات دیگر شرط نیست.'
        );
        $this->assertEqualsWithDelta(
            0.0,
            (float) $supplierWallet->refresh()->balance_hold,
            0.01,
            'و باز هم هیچ هولدی گذاشته نشده باشد.'
        );
    }
}
