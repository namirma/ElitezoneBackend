<?php

namespace App\Actions\Financial;

use App\Exceptions\InsufficientBalanceException;
use App\Exceptions\Wallet\WalletNotFoundException;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

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
        string $type = WalletTransaction::TYPE_WITHDRAW,
        string $status = WalletTransaction::STATUS_CONFIRMED
    ): WalletTransaction {
        // ۱. گارد امنیتی: جلوگیری از مبالغ منفی یا صفر
        if ($amount <= 0) {
            throw new InvalidArgumentException(__('orders.debit_amount_must_be_positive'));
        }

        return DB::transaction(function () use ($user, $amount, $description, $meta, $type, $status) {

            $wallet = Wallet::where('user_id', $user->id)->lockForUpdate()->first();

            if (! $wallet) {
                throw new WalletNotFoundException(__('wallet.errors.not_found'));
            }

            // ۴. بررسی موجودی تحت قفل
            if ($wallet->balance < $amount) {
                throw new InsufficientBalanceException($amount, (float) $wallet->balance);
            }

            $wallet->decrement('balance', $amount);

            $transaction = $wallet->transactions()->create([
                'amount' => $amount,
                'type' => $type,
                'status' => $status,
                'description' => $description,
                'meta' => $meta,
            ]);

            return $transaction;
        });
    }
}
