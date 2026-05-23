<?php

namespace App\Enums\Wallet;

/**
 * Transaction statuses for WalletTransaction (STATUS column).
 *
 * Mirrors WalletTransaction::STATUS_PENDING / STATUS_CONFIRMED / STATUS_FAILED / STATUS_REJECTED.
 */
enum WalletTransactionStatus: string
{
    case Pending = 'pending';
    case Confirmed = 'confirmed';
    case Failed = 'failed';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'در انتظار بررسی',
            self::Confirmed => 'موفق',
            self::Failed => 'ناموفق',
            self::Rejected => 'رد شده',
        };
    }

    public function colorClass(): string
    {
        return match ($this) {
            self::Pending => 'warning',
            self::Confirmed => 'success',
            self::Failed,
            self::Rejected => 'danger',
        };
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Confirmed, self::Failed, self::Rejected]);
    }
}
