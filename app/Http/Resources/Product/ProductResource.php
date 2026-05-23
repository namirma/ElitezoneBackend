<?php

namespace App\Http\Resources\Product;

use App\Models\Product;
use Carbon\Carbon;
use Illuminate\Http\Request;
use App\Http\Resources\BaseJsonResource;

/**
 * @mixin \App\Models\Product
 */
class ProductResource extends BaseJsonResource
{
    public function toArray(Request $request): array
    {
        return [
            Product::CUID => $this->cuid,
            Product::CATEGORY_ID => $this->category_id,
            Product::NAME => $this->name,
            Product::SLUG => $this->slug,
            Product::DESCRIPTION => $this->description,
            Product::SKU => $this->sku,
            Product::IS_FEATURED => (bool) $this->is_featured,
            Product::VIEW_COUNT => $this->view_count,
            Product::ITEMS_PER_PAGE => $this->items_per_page,
            Product::UPDATED_AT => $this->updated_at ? Carbon::parse($this->updated_at)->format('Y-m-d H:i:s') : null,
            Product::CREATED_AT => $this->created_at ? Carbon::parse($this->created_at)->format('Y-m-d H:i:s') : null,

            'category' => $this->whenLoaded('category', function () {
                return [
                    Product::ID => $this->category->id,
                    Product::NAME => $this->category->name,
                    Product::SLUG => $this->category->slug,
                ];
            }),

            'images' => $this->whenLoaded('images', function () {
                return $this->images->map(function ($image) {
                    return [
                        Product::ID => $image->id,
                        'image_path' => $image->image_path,
                        'is_primary' => (bool) $image->is_primary,
                    ];
                });
            }),
            'order_unit_limits' => $this->whenLoaded('orderUnits', function () {
                return $this->orderUnits->map(function ($unit) {
                    return [
                        'order_unit_id' => $unit->id,
                        'unit_name' => $unit->name ?? 'نامشخص',
                        'max_quantity' => (float) $unit->pivot->max_quantity,
                    ];
                });
            }),
        ];
    }
}
