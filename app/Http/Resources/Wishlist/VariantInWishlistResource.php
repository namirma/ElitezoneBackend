<?php

namespace App\Http\Resources\Wishlist;

use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class VariantInWishlistResource extends JsonResource
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

                'image' => isset($this->{ProductVariant::IMAGES}[0]) ? $this->{ProductVariant::IMAGES}[0] : null,
            ]),
        ];
    }
}
