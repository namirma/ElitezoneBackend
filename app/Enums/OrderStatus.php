<?php

namespace App\Enums;

/**
 * Canonical order lifecycle statuses.
 *
 * These mirror the string constants on Order::STATUS_* and can be used
 * wherever a type-safe, IDE-autocompletable value is preferred.
 * The model constants are kept for backward compatibility.
 */
enum OrderStatus: string
{
    case Pending = 'pending';
    case Reserved = 'reserved';
    case ReservedExtended = 'reserved_extended';
    case Processing = 'processing';
    case Preparing = 'preparing';
    case WeighbridgeDone = 'weighbridge_done';
    case WaybillIssued = 'waybill_issued';
    case Shipped = 'shipped';
    case Delivered = 'delivered';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
    case Failed = 'failed';
    case PendingSurplusPayment = 'pending_surplus_payment';

    /** Human-readable Persian label for display. */
    public function label(): string
    {
        return match ($this) {
            self::Pending => 'در انتظار',
            self::Reserved => 'رزرو شده',
            self::ReservedExtended => 'رزرو تمدید شده',
            self::Processing => 'در حال پردازش',
            self::Preparing => 'در حال آماده‌سازی',
            self::WeighbridgeDone => 'باسکول انجام شد',
            self::WaybillIssued => 'بارنامه صادر شد',
            self::Shipped => 'ارسال شد',
            self::Delivered => 'تحویل داده شد',
            self::Completed => 'تکمیل شد',
            self::Cancelled => 'لغو شد',
            self::Failed => 'ناموفق',
            self::PendingSurplusPayment => 'در انتظار پرداخت مازاد',
        };
    }

    /** Returns true for any terminal (non-changeable) state. */
    public function isTerminal(): bool
    {
        return in_array($this, [self::Completed, self::Cancelled, self::Failed]);
    }

    /** Returns true for any active/in-progress state. */
    public function isActive(): bool
    {
        return ! $this->isTerminal();
    }
}
