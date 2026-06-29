<?php

namespace App\Http\Resources\Wishlist;

use App\Http\Resources\BaseJsonResource;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Http\Request;

/**
 * @mixin \App\Models\ProductVariant
 */
class VariantInWishlistResource extends BaseJsonResource
{
    public function toArray(Request $request): array
    {
        return [
            ProductVariant::ID => $this->id,
            ProductVariant::NAME => $this->name,
            ProductVariant::SKU => $this->sku,
            ProductVariant::STOCK_QUANTITY => (string) $this->{ProductVariant::STOCK_QUANTITY},
            'min_price' => isset($this->min_price) ? (string) $this->min_price : null,
            'prices' => $this->whenLoaded('prices', function () {
                return $this->prices->map(function ($price) {
                    $final = isset($price->final_price)
                        ? (float) $price->final_price
                        : (float) ($price->special_price ?: $price->general_price);

                    return [
                        'price_id' => (string) $price->id,
                        'factory' => [
                            'id' => (string) $price->factory->id,
                            'name' => $price->factory->name,
                        ],
                        'factory_location' => [
                            'id' => (string) $price->factoryLocation->id,
                            'name' => $price->factoryLocation->name,
                        ],
                        'general_price' => (string) $price->general_price,
                        'final_price' => (string) $final,
                    ];
                })->values();
            }),
            'product' => $this->whenLoaded('product', [
                Product::ID => $this->product->id,
                Product::NAME => $this->product->name,
                Product::SLUG => $this->product->slug,

                'image' => isset($this->{ProductVariant::IMAGES}[0]) ? $this->{ProductVariant::IMAGES}[0] : null,
            ]),
        ];
    }
}
