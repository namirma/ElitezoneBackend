<?php

namespace App\Enums\Order;

/**
 * Payment states for an Order (Order::PAYMENT_STATUS column).
 *
 * Mirrors Order::PAYMENT_PENDING / PAYMENT_PAID / PAYMENT_REFUNDED etc.
 */
enum PaymentStatus: string
{
    case Pending = 'pending';
    case Paid = 'paid';
    case Failed = 'failed';
    case RefundPending = 'refund_pending';
    case Refunded = 'refunded';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'پرداخت نشده',
            self::Paid => 'پرداخت شده',
            self::Failed => 'ناموفق',
            self::RefundPending => 'در انتظار استرداد',
            self::Refunded => 'مسترد شد',
        };
    }

    public function isPaid(): bool
    {
        return $this === self::Paid;
    }

    public function isRefundable(): bool
    {
        return $this === self::Paid;
    }
}
