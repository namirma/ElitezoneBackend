<?php

namespace App\Actions\Financial;

use App\Models\User;
use App\Models\WalletTransaction;
use Illuminate\Support\Facades\DB;
use App\Events\WalletFunded;

class FundWalletAction
{
    public function execute(User $user, float $amount, ?string $description = null, ?array $meta = []): WalletTransaction
    {
        return DB::transaction(function () use ($user, $amount, $description, $meta) {
            // ۱. ایجاد تراکنش
            $transaction = $user->walletTransactions()->create([
                'amount' => $amount,
                'type' => 'deposit', // یا TransactionType::DEPOSIT
                'description' => $description,
                'meta' => $meta,
                'balance_before' => $user->wallet_balance,
                'balance_after' => $user->wallet_balance + $amount,
            ]);

            // ۲. آپدیت موجودی کاربر (به جای ذخیره در جدول wallet جداگانه، معمولاً در user یا جدول wallet کش می‌شود)
            $user->increment('wallet_balance', $amount);

            // ۳. فایر کردن ایونت (برای پیامک و ...)
            event(new WalletFunded($user, $transaction));

            return $transaction;
        });
    }
}
