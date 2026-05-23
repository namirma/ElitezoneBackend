<?php

namespace App\Http\Resources\Product;

use App\Models\ProductVariant;
use Illuminate\Http\Request;
use App\Http\Resources\BaseJsonResource;

class ProductVariantResource extends BaseJsonResource
{
    public function toArray(Request $request): array
    {
        return [
            ProductVariant::ID => $this->getKey(),
            ProductVariant::PRODUCT_ID => (string) $this->{ProductVariant::PRODUCT_ID},
            ProductVariant::NAME => $this->{ProductVariant::NAME},
            ProductVariant::SKU => $this->{ProductVariant::SKU},
            ProductVariant::STOCK_QUANTITY => (string) $this->{ProductVariant::STOCK_QUANTITY},
            ProductVariant::VIEW_COUNT => (string) $this->{ProductVariant::VIEW_COUNT},
            ProductVariant::DISCOUNT_VALUE => $this->whenNotNull($this->{ProductVariant::DISCOUNT_VALUE}),
            ProductVariant::DISCOUNT_TYPE => $this->whenNotNull($this->{ProductVariant::DISCOUNT_TYPE}),
            ProductVariant::DISCOUNT_START_DATE => optional($this->{ProductVariant::DISCOUNT_START_DATE})->format('Y-m-d H:i:s'),
            ProductVariant::DISCOUNT_END_DATE => optional($this->{ProductVariant::DISCOUNT_END_DATE})->format('Y-m-d H:i:s'),
            'active_discount_details' => $this->getActiveDiscountDetails(),
            'min_price' => isset($this->min_price) ? (string) $this->min_price : null,
            'attributes' => ProductVariantAttributeResource::collection(
                $this->whenLoaded('variantAttributes', $this->variantAttributes)
            ),
            'prices' => $this->whenLoaded('prices', function () use ($request) {
                $withDebug = $request->boolean('debug_prices');

                return $this->prices->map(function ($price) use ($withDebug) {
                    $final = isset($price->final_price)
                        ? (float) $price->final_price
                        : (float) ($price->special_price ?: $price->general_price);

                    $row = [
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
                        'special_price' => (string) $price->special_price,
                        'golden_figure' => (string) ($price->golden_figure ?? 0),
                        'final_price' => (string) $final,
                        'role_special_price' => isset($price->role_special_price) ? (string) $price->role_special_price : null,
                        'role_general_price' => isset($price->role_general_price) ? (string) $price->role_general_price : null,
                        'role_golden_figure' => isset($price->role_golden_figure) ? (string) $price->role_golden_figure : null,
                    ];

                    if ($withDebug && isset($price->pricing_debug) && is_array($price->pricing_debug)) {
                        $row['pricing_debug'] = $price->pricing_debug;
                    }

                    return $row;
                })->values();
            }),
            ProductVariant::CREATED_AT => optional($this->{ProductVariant::CREATED_AT})->format('Y-m-d H:i:s'),
            ProductVariant::UPDATED_AT => optional($this->{ProductVariant::UPDATED_AT})->format('Y-m-d H:i:s'),
        ];
    }
}
