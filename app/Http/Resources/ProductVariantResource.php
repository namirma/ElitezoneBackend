<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use App\Models\ProductVariant;

class ProductVariantResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            ProductVariant::PRODUCT_ID     => (string)$this->product_id,
            ProductVariant::NAME           => $this->name,
            ProductVariant::SKU            => $this->sku,
            ProductVariant::IS_ACTIVE      => (bool)$this->is_active,
            ProductVariant::STOCK_QUANTITY => (string)$this->stock_quantity,

            'attributes' => ProductVariantAttributeResource::collection(
                $this->whenLoaded('variantAttributes', $this->variantAttributes)
            ),

            'prices' => $this->whenLoaded('prices', function () {
                return $this->prices->map(function ($price) {
                    return [
                        'price_id' => (string)$price->id,
                        'factory'  => [
                            'id'   => (string)$price->factory->id,
                            'name' => $price->factory->name,
                        ],
                        'factory_location' => [
                            'id'   => (string)$price->factoryLocation->id,
                            'name' => $price->factoryLocation->name,
                        ],
                        'general_price' => (string)$price->general_price,
                        'special_price' => (string)$price->special_price,
                        'golden_figure' => (string)$price->golden_figure,
                    ];
                })->values();
            }),

            ProductVariant::CREATED_AT => optional($this->created_at)->format('Y-m-d H:i:s'),
            ProductVariant::UPDATED_AT => optional($this->updated_at)->format('Y-m-d H:i:s'),
        ];
    }
}
