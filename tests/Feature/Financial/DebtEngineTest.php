<?php

declare(strict_types=1);

namespace Tests\Feature\Financial;

use App\Actions\Financial\RequestWithdrawalAction;
use App\Models\DebtEntry;
use App\Models\User;
use App\Models\UserBankAccount;
use App\Models\Wallet;
use App\Services\Financial\DebtEngine;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * فاز ۹ — بند ۳.۱۲ و ۴.۷ سند.
 *
 * ادعای مرکزی: **مانده و دفتر همیشه هم‌خوان‌اند**. همان دلیلی که
 * `LedgerReconciler` برای کیف پول‌ها وجود دارد — یک ستونِ مانده بدون دفتری که
 * بشود از آن بازسازی‌اش کرد، هر اشتباهی را نامرئی می‌کند.
 */
class DebtEngineTest extends TestCase
{
    use DatabaseTransactions;

    private DebtEngine $engine;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->engine = app(DebtEngine::class);
        $this->user = User::factory()->create();
    }

    private function ledgerSum(): float
    {
        return (float) DebtEntry::where(DebtEntry::USER_ID, $this->user->id)->sum(DebtEntry::AMOUNT);
    }

    #[Test]
    public function the_balance_and_the_ledger_always_agree(): void
    {
        $this->engine->incur($this->user->id, 500_000, DebtEntry::TYPE_OVERWEIGHT);
        $this->engine->incur($this->user->id, 300_000, DebtEntry::TYPE_RETURN_DAMAGE);
        $this->engine->repay($this->user->id, 200_000);

        $this->assertSame(600_000.0, $this->engine->balanceFor($this->user->id));
        $this->assertSame(600_000.0, $this->ledgerSum(),
            'جمعِ دفتر باید دقیقاً برابر مانده باشد، وگرنه هیچ‌کس نمی‌داند کدام درست است');
    }

    #[Test]
    public function a_repayment_is_a_new_row_not_an_edit(): void
    {
        $incurred = $this->engine->incur($this->user->id, 100_000, DebtEntry::TYPE_OVERWEIGHT);
        $this->engine->repay($this->user->id, 100_000);

        $this->assertSame(100_000.0, (float) $incurred->fresh()->{DebtEntry::AMOUNT},
            'ردیفِ اصلی دست‌نخورده می‌ماند — دفتر append-only است');
        $this->assertSame(2, DebtEntry::where(DebtEntry::USER_ID, $this->user->id)->count());
        $this->assertSame(0.0, $this->engine->balanceFor($this->user->id));
    }

    #[Test]
    public function over_repaying_never_drives_the_balance_negative(): void
    {
        $this->engine->incur($this->user->id, 100_000, DebtEntry::TYPE_OVERWEIGHT);
        $this->engine->repay($this->user->id, 999_999_999);

        $this->assertSame(0.0, $this->engine->balanceFor($this->user->id));
        $this->assertSame(0.0, $this->ledgerSum(),
            'مانده‌ی منفی یعنی «شرکت به کاربر بدهکار است» — آن ادعای دیگری است و جایش کیف پول است');
    }

    #[Test]
    public function the_credit_limit_decides_whether_the_order_is_halted(): void
    {
        $this->user->forceFill([User::CREDIT_LIMIT => 1_000_000])->save();
        $this->engine->incur($this->user->id, 800_000, DebtEntry::TYPE_OVERWEIGHT);

        $this->assertTrue($this->engine->fitsWithinCreditLimit($this->user->id, 200_000));
        $this->assertFalse($this->engine->fitsWithinCreditLimit($this->user->id, 200_001));
    }

    #[Test]
    public function a_zero_credit_limit_is_the_default_and_admits_nothing(): void
    {
        $this->assertSame(0.0, (float) $this->user->{User::CREDIT_LIMIT},
            'اعتبار دادن باید تصمیمِ صریحِ بک‌آفیس باشد، نه پیش‌فرضِ هر کاربر تازه');
        $this->assertFalse($this->engine->fitsWithinCreditLimit($this->user->id, 1));
    }

    #[Test]
    public function debt_blocks_a_wallet_withdrawal(): void
    {
        Wallet::create([
            Wallet::USER_ID => $this->user->id,
            Wallet::BALANCE => 10_000_000,
            Wallet::BALANCE_HOLD => 0,
        ]);

        $account = UserBankAccount::create([
            'user_id' => $this->user->id,
            'shaba' => 'IR'.str_repeat('1', 24),
            'bank_name' => 'پاسارگاد',
            'account_holder_name' => 'تست',
            UserBankAccount::STATUS => UserBankAccount::STATUS_APPROVED,
        ]);

        $this->engine->incur($this->user->id, 1, DebtEntry::TYPE_OVERWEIGHT);

        $this->expectExceptionMessage(__('wallet.withdrawal_blocked_by_debt'));

        app(RequestWithdrawalAction::class)->execute($this->user, 1_000_000, (int) $account->id);
    }

    #[Test]
    public function a_non_positive_amount_is_refused_by_the_engine(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        // همان invariant کیف پول: مبلغِ منفی معنای عملیات را برعکس می‌کند و
        // گاردهای «کافی بودن» با `<` از کنارش رد می‌شوند.
        $this->engine->incur($this->user->id, -5, DebtEntry::TYPE_ADJUSTMENT);
    }
}
