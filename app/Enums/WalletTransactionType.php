<?php

namespace App\Enums;

/**
 * Transaction types for WalletTransaction (TYPE column).
 *
 * Mirrors WalletTransaction::TYPE_DEPOSIT / TYPE_WITHDRAW / TYPE_FEE_HOLD / TYPE_FEE_RELEASE.
 */
enum WalletTransactionType: string
{
    case Deposit = 'deposit';
    case Withdraw = 'withdraw';
    case FeeHold = 'fee_hold';
    case FeeRelease = 'fee_release';

    public function label(): string
    {
        return match ($this) {
            self::Deposit => 'واریز',
            self::Withdraw => 'برداشت',
            self::FeeHold => 'رزرو کارمزد',
            self::FeeRelease => 'آزادسازی کارمزد',
        };
    }

    public function isCredit(): bool
    {
        return $this === self::Deposit;
    }

    public function isDebit(): bool
    {
        return in_array($this, [self::Withdraw, self::FeeHold]);
    }
}
