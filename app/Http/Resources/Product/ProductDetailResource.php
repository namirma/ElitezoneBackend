<?php

namespace App\Http\Resources\Product;

use App\Models\Product;
use Carbon\Carbon;
use Illuminate\Http\Request;
use App\Http\Resources\BaseJsonResource;
use App\Http\Resources\Order\OrderUnitResource;

/**
 * @mixin \App\Models\Product
 */
class ProductDetailResource extends BaseJsonResource
{
    public function toArray(Request $request): array
    {
        return [
            Product::CUID => $this->cuid,
            Product::CATEGORY_ID => $this->category_id,
            Product::NAME => $this->name,
            Product::SLUG => $this->slug,
            Product::DESCRIPTION => $this->description,
            Product::SHIPPING_PROMO => $this->shipping_promo,
            Product::SKU => $this->sku,
            Product::IS_FEATURED => (bool) $this->is_featured,
            Product::VIEW_COUNT => $this->view_count,
            Product::ITEMS_PER_PAGE => $this->items_per_page,
            Product::CREATED_AT => $this->created_at ? Carbon::parse($this->created_at)->format('Y-m-d H:i:s') : null,
            Product::UPDATED_AT => $this->updated_at ? Carbon::parse($this->updated_at)->format('Y-m-d H:i:s') : null,

            'category' => [
                'id' => $this->category->id,
                'name' => $this->category->name,
                'slug' => $this->category->slug,
            ],

            'images' => ProductImageResource::collection($this->whenLoaded('images')),
            'videos' => ProductVideoResource::collection($this->whenLoaded('videos')),
            'variants' => ProductVariantResource::collection($this->whenLoaded('variants')),
            'order_units' => OrderUnitResource::collection($this->whenLoaded('orderUnits')),
            'offers' => OfferResource::collection($this->whenLoaded('offers')),
        ];
    }
}
