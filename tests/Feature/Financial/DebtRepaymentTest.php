<?php

declare(strict_types=1);

namespace Tests\Feature\Financial;

use App\Models\DebtEntry;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Services\Financial\DebtEngine;
use App\Services\Financial\LedgerReconciler;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * بازپرداخت بدهی از کیف پول — `POST /api/profile/debts/repay`.
 *
 * ⚠️ **چرا این اندپوینت لازم بود.** بدهی هم ثبت سفارش را می‌بست
 * (`PlaceOrderAction::assertNotInDebt`) هم برداشت را
 * (`RequestWithdrawalAction`)، ولی تنها راه‌های تسویه‌اش سه مسیرِ رویدادمحور
 * بودند: اضافه‌بار، خسارت عودت، و سقف خسارت. کاربری که بدهی‌اش از یکی از
 * آن‌ها آمده بود و آن رویداد دیگر تکرار نمی‌شد، **برای همیشه قفل می‌ماند** —
 * نه خودش کاری می‌توانست بکند نه ادمین (که فقط `credit_limit` را می‌گذارد،
 * نه مانده را صفر می‌کند).
 */
class DebtRepaymentTest extends TestCase
{
    use DatabaseTransactions;

    private User $user;

    private User $systemUser;

    private DebtEngine $debtEngine;

    protected function setUp(): void
    {
        parent::setUp();

        $system = User::factory()->create();
        $this->systemUser = $system;
        config(['financial.system_user_id' => $system->id]);
        Wallet::create([Wallet::USER_ID => $system->id, Wallet::BALANCE => 0, Wallet::BALANCE_HOLD => 0]);

        $this->user = User::factory()->create();
        Wallet::create([
            Wallet::USER_ID => $this->user->id,
            Wallet::BALANCE => 5_000_000,
            Wallet::BALANCE_HOLD => 0,
        ]);

        $this->debtEngine = app(DebtEngine::class);
    }

    private function incurDebt(float $amount): void
    {
        $this->debtEngine->incur(
            (int) $this->user->id, $amount, DebtEntry::TYPE_ADJUSTMENT, null, 'بدهی آزمایشی'
        );
    }

    #[Test]
    public function a_debtor_can_clear_the_whole_balance_from_their_wallet(): void
    {
        $this->incurDebt(2_000_000);
        Sanctum::actingAs($this->user);

        $this->postJson('/api/profile/debts/repay')->assertOk();

        $this->assertSame(0.0, $this->debtEngine->balanceFor((int) $this->user->id));
        $this->assertSame(
            3_000_000.0,
            (float) Wallet::where(Wallet::USER_ID, $this->user->id)->value(Wallet::BALANCE)
        );
    }

    #[Test]
    public function a_partial_amount_leaves_the_remainder_owing(): void
    {
        $this->incurDebt(2_000_000);
        Sanctum::actingAs($this->user);

        $this->postJson('/api/profile/debts/repay', ['amount' => 500_000])->assertOk();

        $this->assertSame(1_500_000.0, $this->debtEngine->balanceFor((int) $this->user->id));
    }

    /** پرداختِ بیش از بدهی نباید کاربر را طلبکار کند. */
    #[Test]
    public function paying_more_than_the_balance_is_clamped_to_the_balance(): void
    {
        $this->incurDebt(1_000_000);
        Sanctum::actingAs($this->user);

        $this->postJson('/api/profile/debts/repay', ['amount' => 4_000_000])->assertOk();

        $this->assertSame(0.0, $this->debtEngine->balanceFor((int) $this->user->id));
        $this->assertSame(
            4_000_000.0,
            (float) Wallet::where(Wallet::USER_ID, $this->user->id)->value(Wallet::BALANCE)
        );
    }

    /**
     * بازپرداخت باید پول را **جابه‌جا** کند، نه نابود.
     *
     * ⚠️ رگرسیونِ یک نشتِ کاملاً خاموش. `FundWalletAction` پیش‌فرضش ردیفِ
     * ‏(deposit / pending) است — یعنی *درخواستِ* واریز برای فیشِ کاربر، نه
     * واریز. برای یک انتقالِ داخلی هیچ ادمینی چنین ردیفی را تأیید نمی‌کرد،
     * پس مبلغ از خریدار کسر می‌شد و به کیفِ سازه نمی‌رسید.
     *
     * و `financial:doctor` این را **نمی‌گرفت**: `LedgerReconciler` ترکیبِ
     * ‏(deposit / pending) را عمداً «صفر اثر» بازپخش می‌کند، پس هر دو کیف با
     * دفترِ خودشان می‌خواندند؛ و `unbalancedOrders()` فقط ردیف‌های دارای
     * ‏`order_id` را جمع می‌زند، در حالی که هیچ‌کدام از دو لِگِ بازپرداخت
     * ‏`order_id` ندارند (بدهی می‌تواند بی‌سفارش باشد). گزارش سبز می‌ماند و
     * پول ناپدید می‌شد.
     *
     * پس assertion اصلی «drift» نیست — **بقای جمعِ موجودی‌ها** است.
     */
    #[Test]
    public function repayment_moves_the_money_to_the_system_wallet_and_destroys_none_of_it(): void
    {
        // موجودیِ افتتاحیه باید ردیفِ دفتر داشته باشد وگرنه کیف از همان ابتدا
        // مغایر است و ادعای «بدونِ drift» بی‌معنا می‌شود.
        Wallet::where(Wallet::USER_ID, $this->user->id)->firstOrFail()
            ->transactions()->create([
                WalletTransaction::AMOUNT => 5_000_000,
                WalletTransaction::TYPE => WalletTransaction::TYPE_DEPOSIT,
                WalletTransaction::STATUS => WalletTransaction::STATUS_CONFIRMED,
                WalletTransaction::DESCRIPTION => 'موجودی افتتاحیه',
            ]);

        $this->incurDebt(2_000_000);
        $totalBefore = (float) Wallet::sum(Wallet::BALANCE);

        Sanctum::actingAs($this->user);
        $this->postJson('/api/profile/debts/repay', ['amount' => 2_000_000])->assertOk();

        $this->assertSame(
            $totalBefore,
            (float) Wallet::sum(Wallet::BALANCE),
            'بازپرداخت فقط پول را جابه‌جا می‌کند؛ جمعِ موجودی‌ها نباید تغییر کند.'
        );

        $this->assertSame(
            2_000_000.0,
            (float) Wallet::where(Wallet::USER_ID, $this->systemUser->id)->value(Wallet::BALANCE)
        );

        $credit = WalletTransaction::query()
            ->whereRelation('wallet', Wallet::USER_ID, $this->systemUser->id)
            ->latest(WalletTransaction::ID)
            ->firstOrFail();

        $this->assertSame(WalletTransaction::TYPE_DEPOSIT, $credit->{WalletTransaction::TYPE});
        $this->assertSame(WalletTransaction::STATUS_CONFIRMED, $credit->{WalletTransaction::STATUS});
        // نشانه‌ی فراخوان باید روی *هر دو* لِگ بماند؛ پیش از این
        // ‏`FundWalletAction` کلِ `$extraMeta` را دور می‌ریخت و ردیفِ بستانکار
        // به هیچ رویدادی قابلِ ردیابی نبود.
        $this->assertSame('debt_repayment', $credit->{WalletTransaction::META}['kind'] ?? null);

        // و دفتر باید با موجودی‌ها بخواند — همان چیزی که `financial:doctor` می‌سنجد.
        $reconciler = app(LedgerReconciler::class);
        $this->assertSame([], $reconciler->driftedWallets((int) $this->user->id));
        $this->assertSame([], $reconciler->driftedWallets((int) $this->systemUser->id));
    }

    #[Test]
    public function a_user_with_no_debt_is_refused(): void
    {
        Sanctum::actingAs($this->user);

        $this->postJson('/api/profile/debts/repay')->assertStatus(422);
    }

    /** موجودیِ ناکافی نباید دفتر بدهی را ببندد. */
    #[Test]
    public function an_empty_wallet_cannot_clear_the_debt(): void
    {
        $this->incurDebt(9_000_000);
        Sanctum::actingAs($this->user);

        // ۴۰۲ و نه ۴۲۲ — `InsufficientBalanceException` کدِ خودش را دارد.
        $this->postJson('/api/profile/debts/repay')->assertStatus(402);

        // نکته‌ی اصلیِ این تست: کسرِ ناموفق نباید دفتر را ببندد. ترتیبِ
        // «اول کیف پول، بعد دفتر» دقیقاً برای همین است.
        $this->assertSame(9_000_000.0, $this->debtEngine->balanceFor((int) $this->user->id));
    }

    #[Test]
    public function the_endpoint_requires_authentication(): void
    {
        $this->postJson('/api/profile/debts/repay')->assertUnauthorized();
    }

    /**
     * دفترهای مالی نباید با حذفِ والدشان ناپدید شوند.
     *
     * `wallet_transactions`/`debt_entries` صریحاً append-only طراحی شده‌اند تا
     * `financial:doctor` بتواند موجودی را بازپخش کند؛ `cascadeOnDelete` همان
     * تضمین را نقض می‌کرد — یک `$user->delete()` کلِ تاریخچه را می‌برد.
     */
    #[Test]
    public function a_user_with_a_debt_ledger_cannot_be_hard_deleted(): void
    {
        $this->incurDebt(1_000_000);

        $this->expectException(\Illuminate\Database\QueryException::class);

        DB::table('users')->where('id', $this->user->id)->delete();
    }
}
