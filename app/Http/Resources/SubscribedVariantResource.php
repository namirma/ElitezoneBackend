<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use App\Models\ProductVariant;
use App\Models\Product;

class SubscribedVariantResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            ProductVariant::ID => $this->id,
            ProductVariant::NAME => $this->name,
            ProductVariant::SKU => $this->sku,
            'product' => $this->whenLoaded('product', [
                Product::ID => $this->product->id,
                Product::NAME => $this->product->name,
                Product::SLUG => $this->product->slug,
            ]),
            'image' => isset($this->{ProductVariant::IMAGES}[0]) ? $this->{ProductVariant::IMAGES}[0] : null,
        ];
    }
}
