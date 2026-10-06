<?php

namespace Tests\Concerns;

use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;

/**
 * «کیف پول کوپن‌های تخفیف» (WCPN) با موجودی. مدیریت، دورِ پنجم (۱۴۰۵/۰۷/۱۴): سهمِ شرکت از کوپن
 * فقط از این کیف پول برداشته می‌شود؛ بی‌موجودی، کوپنِ شرکتی پیش از هر نوشتنی ۴۲۲ می‌گیرد.
 *
 * موجودی با یک ردیفِ دفتر ساخته می‌شود، نه فقط ستونِ balance — وگرنه `financial:doctor`
 * کیف پول را «موجودی از هیچ‌جا» می‌بیند و تست‌هایی که دفتر را بازپخش می‌کنند قرمز می‌شوند.
 */
trait FundsCouponWallet
{
    protected function fundCouponWallet(float $balance): User
    {
        $user = User::factory()->create();
        config(['financial.coupon_wallet_user_id' => $user->id]);

        $wallet = Wallet::create([Wallet::USER_ID => $user->id, Wallet::BALANCE => $balance, Wallet::BALANCE_HOLD => 0]);
        $wallet->transactions()->create([
            WalletTransaction::AMOUNT => $balance,
            WalletTransaction::TYPE => WalletTransaction::TYPE_DEPOSIT,
            WalletTransaction::STATUS => WalletTransaction::STATUS_CONFIRMED,
            WalletTransaction::DESCRIPTION => 'fixture: coupon wallet funding',
        ]);

        return $user;
    }
}
