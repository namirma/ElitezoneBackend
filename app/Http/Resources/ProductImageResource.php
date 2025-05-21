<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use App\Models\ProductImage;
use Carbon\Carbon;

class ProductImageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            ProductImage::ID         => (string)$this->id,
            ProductImage::PRODUCT_ID => (string)$this->product_id,
            ProductImage::IMAGE_PATH => $this->image_path,
            ProductImage::IS_PRIMARY => (bool)$this->is_primary,
            ProductImage::SORT_ORDER => (string)$this->sort_order,
            ProductImage::CREATED_AT => optional($this->created_at)->format('Y-m-d H:i:s'),
            ProductImage::UPDATED_AT => optional($this->updated_at)->format('Y-m-d H:i:s'),
        ];
    }
}
