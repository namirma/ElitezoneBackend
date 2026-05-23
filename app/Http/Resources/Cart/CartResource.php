<?php

namespace App\Http\Resources\Cart;

use App\Models\Cart;
use App\Http\Resources\BaseJsonResource;
use App\Http\Resources\Profile\CustomerTypeResource;
use App\Http\Resources\Profile\BadgeResource;

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
            'created_at' => $this->created_at?->format('Y-m-d H:i:s'),
            'updated_at' => $this->updated_at?->format('Y-m-d H:i:s'),
            'customer_type' => new CustomerTypeResource($this->whenLoaded('customerType')),
            'badge' => new BadgeResource($this->whenLoaded('badge')),
            'items' => CartItemResource::collection($this->whenLoaded('items')),
        ];
    }
}
