<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use App\Models\ProductCategory;

class ProductCategoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            ProductCategory::ID           => $this->id,
            ProductCategory::NAME         => $this->name,
            ProductCategory::SLUG         => $this->slug,
            ProductCategory::DESCRIPTION  => $this->description,
            ProductCategory::IMAGE        => $this->image,
            ProductCategory::IS_ACTIVE    => (bool) $this->is_active,
            ProductCategory::CREATED_AT   => $this->created_at,
            ProductCategory::UPDATED_AT   => $this->updated_at,
        ];
    }
}
