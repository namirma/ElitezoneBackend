<?php

namespace App\Enums\Wallet;

/**
 * Transaction types for WalletTransaction (TYPE column).
 *
 * Mirrors WalletTransaction::TYPE_DEPOSIT / TYPE_WITHDRAW / TYPE_FEE_HOLD /
 * TYPE_FEE_RELEASE / TYPE_HOLD_RELEASE. Parity with the model constants is
 * pinned by Tests\Feature\Enums\EnumModelConstantParityTest — adding a
 * constant on the model without its enum case fails the suite.
 */
enum WalletTransactionType: string
{
    case Deposit = 'deposit';
    case Withdraw = 'withdraw';
    case FeeHold = 'fee_hold';
    case FeeRelease = 'fee_release';
    case HoldRelease = 'hold_release';

    public function label(): string
    {
        return match ($this) {
            self::Deposit => 'واریز',
            self::Withdraw => 'برداشت',
            self::FeeHold => 'رزرو کارمزد',
            self::FeeRelease => 'آزادسازی کارمزد',
            self::HoldRelease => 'آزادسازی هولد',
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
