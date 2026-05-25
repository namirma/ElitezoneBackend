<?php

namespace App\Models\Concerns;

use App\Models\Wallet;
use Illuminate\Database\Eloquent\Relations\HasOne;

trait HasWallet
{
    /**
     * رابطه با کیف پول
     */
    public function wallet(): HasOne
    {
        return $this->hasOne(Wallet::class, 'user_id');
    }

    /**
     * متد کمکی: دریافت موجودی (با هندل کردن نبود کیف پول)
     */
    public function getBalanceAttribute(): float
    {
        return $this->wallet ? (float) $this->wallet->balance : 0.0;
    }

    /**
     * متد کمکی: آیا موجودی کافی دارد؟
     */
    public function hasCredit(float $amount): bool
    {
        return $this->balance >= $amount;
    }

    /**
     * متد کمکی: ساخت یا دریافت کیف پول
     */
    public function getWalletOrCreate(): Wallet
    {
        // Wallets table has: user_id, balance, balance_hold — no is_active column.
        return $this->wallet()->firstOrCreate(
            ['user_id' => $this->id],
            ['balance' => 0, 'balance_hold' => 0],
        );
    }
}
