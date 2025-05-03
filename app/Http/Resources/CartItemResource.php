<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;
use App\Models\CartItem;

class CartItemResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            CartItem::ID                  => $this->id,
            CartItem::CART_ID             => $this->cart_id,

            // روابط محصولات
            'product_variant'    => new ProductVariantResource(
                $this->whenLoaded('productVariant')
            ),
            'factory'            => new FactoryResource(
                $this->whenLoaded('factory')
            ),
            'factory_location'   => new FactoryLocationResource(
                $this->whenLoaded('factoryLocation')
            ),
            'shipping_method'    => new ShippingMethodResource(
                $this->whenLoaded('shippingMethod')
            ),

            CartItem::QUANTITIES      => $this->quantities,
            CartItem::PRICE           => $this->price,
            CartItem::FINAL_PRICE     => $this->final_price,
            CartItem::VAT_AMOUNT      => $this->vat_amount,
            CartItem::TOTAL_WITH_VAT  => $this->total_with_vat,
            CartItem::WITH_VAT        => $this->with_vat,
            CartItem::CREATED_AT     => $this->created_at?->format('Y-m-d H:i:s'),
            CartItem::UPDATED_AT     => $this->updated_at?->format('Y-m-d H:i:s'),
        ];
    }
}
