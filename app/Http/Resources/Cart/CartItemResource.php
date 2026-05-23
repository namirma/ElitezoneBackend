<?php

namespace App\Http\Resources\Cart;

use App\Models\CartItem;
use Illuminate\Http\Resources\Json\JsonResource;
use App\Http\Resources\Product\ProductVariantResource;
use App\Http\Resources\Supplier\FactoryResource;
use App\Http\Resources\Supplier\FactoryLocationResource;
use App\Http\Resources\Order\ShippingMethodResource;

class CartItemResource extends JsonResource
{
    public function toArray($request): array
    {
        // 1. شبیه‌سازی واریانت برای فیلتر کردن قیمت‌ها بر اساس کارخانه و لوکیشن آیتم
        $variant = $this->productVariant->replicate();
        $variant->setRelation(
            'prices',
            $this->productVariant->prices
                ->where('factory_id', (int) $this->factory_id)
                ->where('factory_location_id', (int) $this->factory_location_id)
        );

        // 2. دیکد کردن و تضمین ساختار صحیح برای quantities
        // اگر رشته است دیکد کن، در غیر این صورت خود آرایه را بردار
        $quantities = is_string($this->quantities)
            ? json_decode($this->quantities, true)
            : $this->quantities;

        return [
            CartItem::ID => (string) $this->id,
            CartItem::CART_ID => (string) $this->cart_id,
            'product_variant' => new ProductVariantResource($variant),

            'factory' => new FactoryResource($this->whenLoaded('factory')),
            'factory_location' => new FactoryLocationResource($this->whenLoaded('factoryLocation')),
            'shipping_method' => new ShippingMethodResource($this->whenLoaded('shippingMethod')),

            CartItem::QUANTITIES => (object) ($quantities ?? []),

            CartItem::PRICE => (string) $this->price,
            CartItem::FINAL_PRICE => (string) $this->final_price,
            CartItem::VAT_AMOUNT => (string) $this->vat_amount,
            CartItem::TOTAL_WITH_VAT => (string) $this->total_with_vat,
            CartItem::WITH_VAT => (bool) $this->with_vat,

            'weight' => (string) $this->weight,
            'quantity' => (string) $this->quantity,
            'unit_name' => (string) $this->unit_name,

            CartItem::CREATED_AT => optional($this->created_at)->format('Y-m-d H:i:s'),
            CartItem::UPDATED_AT => optional($this->updated_at)->format('Y-m-d H:i:s'),
        ];
    }
}
