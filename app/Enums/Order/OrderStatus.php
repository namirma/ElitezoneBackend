<?php

namespace App\Enums\Order;

/**
 * Canonical order lifecycle statuses.
 *
 * These mirror the string constants on Order::STATUS_* and can be used
 * wherever a type-safe, IDE-autocompletable value is preferred.
 * The model constants are kept for backward compatibility.
 */
enum OrderStatus: string
{
    /** @see Order::STATUS_PENDING — موقت تا فاز ۴؛ همان‌جا حذف می‌شود. */
    case Pending = 'pending';
    case Reserved = 'reserved';
    case Processing = 'processing';
    case AwaitingWeightCorrection = 'awaiting_weight_correction';
    case Shipped = 'shipped';
    case PartiallyDelivered = 'partially_delivered';
    case Delivered = 'delivered';
    case ReturnedToOrigin = 'returned_to_origin';
    case Cancelled = 'cancelled';

    /**
     * Human-readable Persian label for display.
     *
     * ⚠️ این برچسب‌ها باید با `$orderStatusLabels` در `lang/fa/orders.php` یکی
     * بمانند. آنجا منبعِ نمایشِ API است و اینجا منبعِ نمایشِ enum؛ واگراییِ این دو
     * یعنی یک وضعیت در دو صفحه دو اسم دارد.
     */
    public function label(): string
    {
        return match ($this) {
            self::Pending => 'در انتظار',
            self::Reserved => 'رزرو شده',
            self::Processing => 'در حال آماده‌سازی',
            self::AwaitingWeightCorrection => 'در انتظار اصلاح وزن',
            self::Shipped => 'در حال حمل',
            self::PartiallyDelivered => 'تحویل جزئی',
            self::Delivered => 'تحویل شده',
            self::ReturnedToOrigin => 'عودت به مبدأ',
            self::Cancelled => 'لغو شده',
        };
    }

    /** Returns true for any terminal (non-changeable) state. */
    public function isTerminal(): bool
    {
        return in_array($this, [self::Delivered, self::Cancelled]);
    }

    /** Returns true for any active/in-progress state. */
    public function isActive(): bool
    {
        return ! $this->isTerminal();
    }
}
