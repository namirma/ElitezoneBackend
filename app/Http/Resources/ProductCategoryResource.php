<?php

namespace App\Http\Resources;

use App\Models\ProductCategory;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductCategoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            ProductCategory::ID => $this->id,
            ProductCategory::NAME => $this->name,
            ProductCategory::SLUG => $this->slug,
            ProductCategory::DESCRIPTION => $this->description,
            ProductCategory::IMAGE => $this->image,
        ];
    }
}
