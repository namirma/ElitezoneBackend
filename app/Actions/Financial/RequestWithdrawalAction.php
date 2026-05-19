<?php

namespace App\Actions\Financial;

use App\Models\User;
use App\Models\UserBankAccount;
use App\Models\WalletTransaction;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Safely debit the user's wallet for a withdrawal request.
 *
 * Uses a distributed cache-lock + DB transaction to prevent
 * concurrent double-withdrawals on the same wallet.
 *
 * @throws \Exception When balance is insufficient or another validation fails.
 * @throws LockTimeoutException When another request holds the lock after 10 s.
 */
final class RequestWithdrawalAction
{
    public function __construct(protected DebitWalletAction $debitAction) {}

    public function execute(User $user, float $amount, int $bankAccountId): WalletTransaction
    {
        $lockKey = "wallet_withdraw_lock:user:{$user->id}";

        return Cache::lock($lockKey, 10)->block(10, function () use ($user, $amount, $bankAccountId) {
            $bankAccount = UserBankAccount::where('id', $bankAccountId)
                ->where('user_id', $user->id)
                ->firstOrFail();

            return DB::transaction(function () use ($user, $amount, $bankAccount) {
                return $this->debitAction->execute(
                    user: $user,
                    amount: $amount,
                    description: __('wallet.withdrawal_description', [
                        'shaba' => $bankAccount->shaba,
                        'bank' => $bankAccount->bank_name,
                    ]),
                    meta: [
                        'withdrawal_request' => true,
                        'shaba' => $bankAccount->shaba,
                        'bank_name' => $bankAccount->bank_name,
                        'holder_name' => $bankAccount->account_holder_name,
                    ],
                    type: WalletTransaction::TYPE_WITHDRAW
                );
            });
        });
    }
}
