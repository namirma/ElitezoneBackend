<?php

namespace App\Actions\Financial;

use App\Exceptions\BusinessException;
use App\Models\User;
use App\Models\UserBankAccount;
use App\Models\WalletTransaction;
use App\Services\Financial\DebtEngine;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Safely debit the user's wallet for a withdrawal request.
 *
 * Uses a distributed cache-lock + DB transaction to prevent concurrent
 * double-withdrawals on the same wallet. The wallet is debited immediately and a
 * PENDING withdraw transaction is created — the actual bank settlement happens
 * later (admin-approved by default, or automatically when
 * config('podium.settlement.auto_on_request') is true).
 *
 * @throws \Exception When balance is insufficient or another validation fails.
 * @throws LockTimeoutException When another request holds the lock after 10 s.
 */
final class RequestWithdrawalAction
{
    public function __construct(
        protected DebitWalletAction $debitAction,
        protected SettleWithdrawalAction $settleAction,
        protected DebtEngine $debtEngine,
    ) {}

    public function execute(User $user, float $amount, int $bankAccountId): WalletTransaction
    {
        // بدهکار برداشت نمی‌کند — بند ۳.۱۲، برای **هر دو** نقش خریدار و
        // تأمین‌کننده. همین قاعده است که تضمین می‌کند شارژِ بعدیِ یک
        // تأمین‌کننده‌ی بدهکار صرف تسویه شود و بلافاصله دوباره خارج نشود
        // (بند ۸.۴ روی همین حساب می‌کند).
        if ($this->debtEngine->isInDebt((int) $user->id)) {
            throw new BusinessException(__('wallet.withdrawal_blocked_by_debt'), 422);
        }

        $lockKey = "wallet_withdraw_lock:user:{$user->id}";

        $transaction = Cache::lock($lockKey, 10)->block(10, function () use ($user, $amount, $bankAccountId) {
            // Re-assert ownership AND approval here (defence in depth — the FormRequest
            // already checks it, but the Action must not trust that it was called).
            $bankAccount = UserBankAccount::where('id', $bankAccountId)
                ->where('user_id', $user->id)
                ->where(UserBankAccount::STATUS, UserBankAccount::STATUS_APPROVED)
                ->firstOrFail();

            return DB::transaction(function () use ($user, $amount, $bankAccount) {
                return $this->debitAction->execute(
                    user: $user,
                    amount: $amount,
                    description: __('wallet.withdrawal_description', [
                        'shaba' => $bankAccount->shaba,
                    ]),
                    meta: [
                        'withdrawal_request' => true,
                        'bank_account_id' => $bankAccount->id,
                        'shaba' => $bankAccount->shaba,
                        'bank_name' => $bankAccount->bank_name,
                        'holder_name' => $bankAccount->account_holder_name,
                        'settlement' => ['state' => 'awaiting_approval'],
                    ],
                    type: WalletTransaction::TYPE_WITHDRAW,
                    // PENDING: money has left the wallet but the bank payout is not done yet.
                    // Admin approval (or auto_on_request) drives it to CONFIRMED via settlement.
                    status: WalletTransaction::STATUS_PENDING,
                );
            });
        });

        // Optional fully-automatic mode: settle immediately, OUTSIDE the cache-lock and
        // the DB transaction (never hold a lock across an external HTTP call). A settlement
        // failure here is swallowed — the wallet is already debited and the transaction
        // stays PENDING so an admin can retry; the user's money is never lost.
        if (config('podium.settlement.auto_on_request')) {
            try {
                return $this->settleAction->execute($transaction);
            } catch (\Throwable $e) {
                Log::channel('financial')->warning(
                    "Auto-settlement failed for withdraw tx {$transaction->id}: ".$e->getMessage()
                );

                return $transaction->fresh();
            }
        }

        return $transaction;
    }
}
