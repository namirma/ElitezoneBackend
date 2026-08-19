<?php

namespace App\Http\Resources\Content;

use App\Http\Resources\BaseJsonResource;
use App\Models\AbandonedCart;
use Illuminate\Http\Request;

/**
 * @mixin \App\Models\AbandonedCart
 */
class AbandonedCartResource extends BaseJsonResource
{
    public function toArray(Request $request): array
    {
        return [
            AbandonedCart::ID => $this->id,

            'user' => [
                'id' => $this->user_id,
                'mobile' => $this->whenLoaded('user', fn () => $this->user?->mobile),
                'full_name' => $this->whenLoaded('user', fn () => $this->user?->full_name),
            ],

            'supplier' => [
                'id' => $this->supplier_user_id,
                'full_name' => $this->whenLoaded('supplier', fn () => $this->supplier?->full_name),
            ],

            // اسنپ‌شاتِ ردیف‌ها در لحظه‌ی انقضا. محصولات ممکن است بعداً حذف یا
            // قیمتشان عوض شده باشد، پس این آرایه تنها روایتِ درستِ آن سبد است.
            AbandonedCart::ITEMS => $this->items,
            AbandonedCart::TOTAL_AMOUNT => (string) $this->total_amount,
            AbandonedCart::COUPON_CODE => $this->coupon_code,

            // فاصله‌ی این دو می‌گوید سبد چقدر زنده بود پیش از آنکه رها شود —
            // همان چیزی که گزارش برای تنظیمِ `park_time_minutes` لازم دارد.
            AbandonedCart::CART_CREATED_AT => $this->cart_created_at?->toIso8601String(),
            AbandonedCart::EXPIRED_AT => $this->expired_at?->toIso8601String(),
        ];
    }
}
