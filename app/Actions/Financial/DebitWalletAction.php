<?php

namespace App\Actions\Financial;

use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use Illuminate\Support\Facades\DB;
use Exception;

class DebitWalletAction
{
    /**
     * کسر موجودی از کیف پول و ثبت تراکنش
     */
    public function execute(
        User $user,
        float $amount,
        ?string $description = null,
        array $meta = [],
        string $type = WalletTransaction::TYPE_WITHDRAW
    ): WalletTransaction
    {
        return DB::transaction(function () use ($user, $amount, $description, $meta, $type) {
            $wallet = $user->wallet()->firstOrCreate([]);
            if ($wallet->balance < $amount) {
                throw new Exception(__('wallet.insufficient_balance'));
            }

            $wallet->decrement('balance', $amount);

            $transaction = $wallet->transactions()->create([
                'amount'      => $amount,
                'type'        => $type,
                'status'      => WalletTransaction::STATUS_PENDING,
                'description' => $description,
                'meta'        => $meta,
            ]);

            return $transaction;
        });
    }
}
