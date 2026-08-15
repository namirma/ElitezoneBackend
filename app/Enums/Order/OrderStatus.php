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
    case Reserved = 'reserved';
    case Processing = 'processing';
    case AwaitingWeightCorrection = 'awaiting_weight_correction';
    case Shipped = 'shipped';
    case PartiallyDelivered = 'partially_delivered';
    case Delivered = 'delivered';
    case ReturnedToOrigin = 'returned_to_origin';
    case Cancelled = 'cancelled';

    /**
     * برچسب فارسی، از همان تک‌منبعی که API می‌خواند.
     *
     * پیش از این یک `match` با رشته‌های هاردکد بود — دو نسخه از یک برچسب، که
     * روی `pending` واقعاً واگرا شده بودند («در انتظار» اینجا در برابر «در انتظار
     * تایید (رزرو شده)» در فایل زبان). خواندن از `orders.order_status` هم قید
     * ۱۴.۷ را رعایت می‌کند و هم واگرایی را ساختاراً ناممکن می‌کند.
     */
    public function label(): string
    {
        return __('orders.order_status.'.$this->value);
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
