<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;
use App\Models\Cart;
use App\Models\PriceFilterCategory;

class CartResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            Cart::ID                   => $this->id,
            Cart::USER_ID              => $this->user_id,
            Cart::SESSION_ID           => $this->session_id,
            Cart::CUSTOMER_TYPE_ID     => $this->customer_type_id,
            Cart::BADGE_ID             => $this->badge_id,
            Cart::CREATED_AT => $this->created_at?->format('Y-m-d H:i:s'),
            Cart::UPDATED_AT => $this->updated_at?->format('Y-m-d H:i:s'),
            'customer_type'           => new CustomerTypeResource($this->whenLoaded('customerType')),
            // رابطهٔ نشان (Badge)
            'badge'                   => new BadgeResource($this->whenLoaded('badge')),
            // فیلترهای قیمت
            'price_filter_categories' => PriceFilterCategoryResource::collection(
                $this->whenLoaded('priceFilterCategories')
            ),

            // ** کل آیتم‌ها **
            'items' => CartItemResource::collection(
                $this->whenLoaded('items')
            ),

        ];
    }
}
