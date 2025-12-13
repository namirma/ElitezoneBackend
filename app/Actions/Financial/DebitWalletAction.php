<?php

namespace App\Actions\Financial;

use App\Models\User;
use App\Models\WalletTransaction;
use Illuminate\Support\Facades\DB;
use Exception;

class DebitWalletAction
{
    public function execute(User $user, float $amount, ?string $description = null): WalletTransaction
    {
        return DB::transaction(function () use ($user, $amount, $description) {
            if ($user->wallet_balance < $amount) {
                throw new Exception("موجودی کیف پول کافی نیست.");
            }

            // ۲. ثبت تراکنش
            $transaction = $user->walletTransactions()->create([
                'amount' => -$amount,
                'type' => 'withdraw',
                'description' => $description,
                'balance_before' => $user->wallet_balance,
                'balance_after' => $user->wallet_balance - $amount,
            ]);

            // ۳. کسر موجودی
            $user->decrement('wallet_balance', $amount);

            return $transaction;
        });
    }
}
