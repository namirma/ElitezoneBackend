<?php

namespace Tests\Feature\Financial;

use App\Actions\Financial\ProcessTransactionAction;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * ProcessTransactionAction must not credit a wallet twice when a deposit confirmation
 * is retried or runs concurrently. The pre-check runs on a stale model outside any lock;
 * the in-transaction re-check under lockForUpdate is what actually prevents double-credit.
 *
 * DatabaseTransactions: all writes roll back; the live DB is never mutated.
 */
class ProcessTransactionRaceTest extends TestCase
{
    use DatabaseTransactions;

    /** @test */
    public function a_stale_confirm_does_not_double_credit_the_wallet(): void
    {
        $user = User::factory()->create();
        // Wallet already credited (100) as if a first admin had just confirmed it.
        $wallet = Wallet::create([Wallet::USER_ID => $user->id, Wallet::BALANCE => 100, Wallet::BALANCE_HOLD => 0]);

        $tx = $wallet->transactions()->create([
            WalletTransaction::AMOUNT => 100,
            WalletTransaction::TYPE => WalletTransaction::TYPE_DEPOSIT,
            WalletTransaction::STATUS => WalletTransaction::STATUS_PENDING,
        ]);

        // Simulate the first admin's confirmation landing in the DB AFTER this request
        // loaded $tx — so our in-memory $tx is stale (still "pending").
        WalletTransaction::where('id', $tx->id)->update([
            WalletTransaction::STATUS => WalletTransaction::STATUS_CONFIRMED,
        ]);

        $admin = User::factory()->create();

        $threw = false;
        try {
            app(ProcessTransactionAction::class)->execute($tx, WalletTransaction::STATUS_CONFIRMED, null, $admin);
        } catch (\Exception $e) {
            $threw = true;
        }

        $this->assertTrue($threw, 'A stale confirm must be rejected, not silently re-applied.');
        // Balance must stay 100 — never credited a second time.
        $this->assertEquals(100, (int) $wallet->fresh()->balance);
    }
}
