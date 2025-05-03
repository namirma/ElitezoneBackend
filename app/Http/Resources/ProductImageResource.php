<?php

namespace App\Http\Resources;

use App\Models\ProductImage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductImageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            ProductImage::ID => $this->id,
            ProductImage::PRODUCT_ID => $this->product_id,
            ProductImage::IMAGE_PATH => $this->image_path,
            ProductImage::IS_PRIMARY => (bool) $this->is_primary,
            ProductImage::SORT_ORDER => $this->sort_order,
            ProductImage::CREATED_AT => $this->created_at,
            ProductImage::UPDATED_AT => $this->updated_at,
        ];
    }
}
