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
            ProductVariant::ID => $this->id,
            ProductVariant::PRODUCT_ID => $this->product_id,
            ProductVariant::NAME => $this->name,
            ProductVariant::SKU => $this->sku,
            ProductVariant::IS_ACTIVE => (bool) $this->is_active,
            ProductVariant::STOCK_QUANTITY => $this->stock_quantity,
            ProductVariant::IMAGES => $this->images ?? [],
            ProductVariant::VIDEOS => $this->videos ?? [],
            'attributes' => ProductVariantAttributeResource::collection($this->variantAttributes),
            ProductVariant::CREATED_AT => $this->created_at,
            ProductVariant::UPDATED_AT => $this->updated_at,
        ];
    }
}
