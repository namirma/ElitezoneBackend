<?php

namespace App\Http\Resources;

use App\Models\Cart;
use Illuminate\Http\Resources\Json\JsonResource;

class CartResource extends JsonResource
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
