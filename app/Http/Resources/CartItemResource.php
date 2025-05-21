<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;
use App\Models\CartItem;

class CartItemResource extends JsonResource
{
    public function toArray($request): array
    {
        /* فیلتر فقط قیمت مخصوص همین کارخانه و لوکیشن */
        $variant = $this->productVariant->replicate();          // کپی سبک
        $variant->setRelation(
            'prices',
            $this->productVariant->prices
                ->where('factory_id', (int)$this->factory_id)
                ->where('factory_location_id', (int)$this->factory_location_id)
        );

        return [
            CartItem::ID      => (string)$this->id,
            CartItem::CART_ID => (string)$this->cart_id,

            // واریانت با prices فیلترشده
            'product_variant'  => new ProductVariantResource($variant),

            'factory'          => new FactoryResource($this->whenLoaded('factory')),
            'factory_location' => new FactoryLocationResource($this->whenLoaded('factoryLocation')),
            'shipping_method'  => new ShippingMethodResource($this->whenLoaded('shippingMethod')),

            CartItem::QUANTITIES      => array_map('strval', $this->quantities),
            CartItem::PRICE           => (string)$this->price,
            CartItem::FINAL_PRICE     => (string)$this->final_price,
            CartItem::VAT_AMOUNT      => (string)$this->vat_amount,
            CartItem::TOTAL_WITH_VAT  => (string)$this->total_with_vat,
            CartItem::WITH_VAT        => (bool)$this->with_vat,
            CartItem::CREATED_AT      => optional($this->created_at)->format('Y-m-d H:i:s'),
            CartItem::UPDATED_AT      => optional($this->updated_at)->format('Y-m-d H:i:s'),
        ];
    }
}
