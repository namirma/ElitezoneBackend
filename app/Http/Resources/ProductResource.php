<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use App\Models\Product;
use Carbon\Carbon;

class ProductResource extends JsonResource
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
            Product::IS_ACTIVE => (bool) $this->is_active,
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
        ];
    }
}
