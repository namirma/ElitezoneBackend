<?php

namespace App\Http\Resources\Cart;

use App\Http\Resources\BaseJsonResource;
use App\Http\Resources\Profile\BadgeResource;
use App\Http\Resources\Profile\CustomerTypeResource;
use App\Models\Cart;

/**
 * @mixin Cart
 */
class CartResource extends BaseJsonResource
{
    public function toArray($request): array
    {
        return [
            Cart::ID => $this->id,
            Cart::USER_ID => $this->user_id,
            Cart::SESSION_ID => $this->session_id,
            Cart::CUSTOMER_TYPE_ID => $this->customer_type_id,
            Cart::BADGE_ID => $this->badge_id,

            // ── مهلتِ سبد (بند ۷.۱) ─────────────────────────────────────────
            // `parked_until` مطلق است و `park_remaining_seconds` نسبی. هر دو
            // داده می‌شوند چون فرانت برای شمارشِ معکوس به مقدارِ نسبی نیاز دارد
            // (ساعتِ کلاینت ممکن است با سرور اختلاف داشته باشد) ولی برای نمایشِ
            // «تا ساعت X» به مقدارِ مطلق.
            // null یعنی مهلتی تنظیم نشده — سبدِ خالی یا سبدِ مهمان، نه «منقضی».
            Cart::PARKED_UNTIL => $this->parked_until?->toIso8601String(),
            'park_remaining_seconds' => $this->parkRemainingSeconds(),
            Cart::SUPPLIER_USER_ID => $this->supplier_user_id,
            Cart::ADDRESS_ID => $this->address_id,
            Cart::COUPON_CODE => $this->coupon_code,

            'created_at' => $this->created_at?->format('Y-m-d H:i:s'),
            'updated_at' => $this->updated_at?->format('Y-m-d H:i:s'),
            'customer_type' => new CustomerTypeResource($this->whenLoaded('customerType')),
            'badge' => new BadgeResource($this->whenLoaded('badge')),
            'items' => CartItemResource::collection($this->whenLoaded('items')),
        ];
    }
}
