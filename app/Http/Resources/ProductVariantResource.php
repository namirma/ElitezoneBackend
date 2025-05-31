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
            ProductVariant::ID => (string)$this->id,
            ProductVariant::PRODUCT_ID => (string)$this->{ProductVariant::PRODUCT_ID},
            ProductVariant::NAME => $this->{ProductVariant::NAME},
            ProductVariant::SKU => $this->{ProductVariant::SKU},
            ProductVariant::STOCK_QUANTITY => (string)$this->{ProductVariant::STOCK_QUANTITY},
            ProductVariant::VIEW_COUNT => (string)$this->{ProductVariant::VIEW_COUNT},

            // اطلاعات تخفیف مستقیم واریانت
            ProductVariant::DISCOUNT_VALUE => $this->whenNotNull($this->{ProductVariant::DISCOUNT_VALUE}),
            ProductVariant::DISCOUNT_TYPE => $this->whenNotNull($this->{ProductVariant::DISCOUNT_TYPE}),
            ProductVariant::DISCOUNT_START_DATE => optional($this->{ProductVariant::DISCOUNT_START_DATE})->format('Y-m-d H:i:s'),
            ProductVariant::DISCOUNT_END_DATE => optional($this->{ProductVariant::DISCOUNT_END_DATE})->format('Y-m-d H:i:s'),
            'active_discount_details' => $this->getActiveDiscountDetails(),

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

            ProductVariant::CREATED_AT => optional($this->{ProductVariant::CREATED_AT})->format('Y-m-d H:i:s'),
            ProductVariant::UPDATED_AT => optional($this->{ProductVariant::UPDATED_AT})->format('Y-m-d H:i:s'),
        ];
    }
}
