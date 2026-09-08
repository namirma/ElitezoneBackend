<?php

namespace App\Listeners\Cart;

use App\Events\Cart\CartItemChanged;
use App\Models\CartEvent;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

class LogCartEvent implements ShouldQueue
{
    use InteractsWithQueue;

    /**
     * سبدِ حذف‌شده = این رویداد را بی‌صدا دور بینداز، نه اینکه fail شود.
     *
     * `CartItemChanged` از `SerializesModels` استفاده می‌کند، پس صف موقعِ پردازش
     * دوباره `cart` و `variant` را از دیتابیس می‌خواند. `PlaceOrderAction` سبد را
     * بعد از ثبتِ موفقِ سفارش حذف می‌کند، پس این جاب برای **هر سفارشِ موفق** با
     * `ModelNotFoundException` می‌ترکید — ۱۷ تا از ۲۰ ردیفِ `failed_jobs` همین بود
     * و شکست‌های واقعیِ پیامک را زیر خودش گم می‌کرد.
     *
     * یک رویدادِ تحلیلیِ سبد برای سبدی که دیگر وجود ندارد ارزشی ندارد؛ حذفش
     * رفتار درست است (و مسیرِ استانداردِ خودِ لاراول برای همین حالت).
     */
    public bool $deleteWhenMissingModels = true;

    public function handle(CartItemChanged $event): void
    {
        CartEvent::create([
            CartEvent::CART_ID => $event->cart->id,
            CartEvent::USER_ID => $event->cart->user_id,
            CartEvent::SESSION_ID => $event->cart->session_id,
            CartEvent::PRODUCT_VARIANT_ID => $event->variant->id,
            CartEvent::ACTION_TYPE => $event->actionType,
            CartEvent::QUANTITY_CHANGE => $event->quantityChange,
            CartEvent::UNIT_PRICE_SNAPSHOT => $event->currentPrice,
        ]);
    }
}
