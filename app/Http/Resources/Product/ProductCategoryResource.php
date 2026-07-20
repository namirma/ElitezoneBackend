<?php

namespace App\Http\Resources\Product;

use App\Http\Resources\BaseJsonResource;
use App\Models\ProductCategory;
use Illuminate\Http\Request;

/**
 * @mixin \App\Models\ProductCategory
 */
class ProductCategoryResource extends BaseJsonResource
{
    public function toArray(Request $request): array
    {
        return [
            ProductCategory::ID => $this->id,
            ProductCategory::NAME => $this->name,
            ProductCategory::SLUG => $this->slug,
            ProductCategory::DESCRIPTION => $this->description,
            ProductCategory::IMAGE => $this->image,
            ProductCategory::IS_ACTIVE => $this->is_active,
            'products_count' => $this->whenCounted('products'),
        ];
    }
}
