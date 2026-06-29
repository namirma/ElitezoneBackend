<?php

namespace App\Enums\Wallet;

/**
 * Transaction statuses for WalletTransaction (STATUS column).
 *
 * Mirrors WalletTransaction::STATUS_PENDING / STATUS_CONFIRMED / STATUS_HOLD /
 * STATUS_FAILED / STATUS_REJECTED. `Hold` is the escrow status used by the
 * order-financial flow (FinancialCodes::STATUS_HOLD) — funds parked in
 * balance_hold awaiting release to available.
 */
enum WalletTransactionStatus: string
{
    case Pending = 'pending';
    case Confirmed = 'confirmed';
    case Hold = 'hold';
    case Failed = 'failed';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'در انتظار بررسی',
            self::Confirmed => 'موفق',
            self::Hold => 'هولد (در انتظار آزادسازی)',
            self::Failed => 'ناموفق',
            self::Rejected => 'رد شده',
        };
    }

    public function colorClass(): string
    {
        return match ($this) {
            self::Pending => 'warning',
            self::Confirmed => 'success',
            self::Hold => 'info',
            self::Failed,
            self::Rejected => 'danger',
        };
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Confirmed, self::Failed, self::Rejected]);
    }
}
