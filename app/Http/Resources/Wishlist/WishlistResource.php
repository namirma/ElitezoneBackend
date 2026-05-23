<?php

namespace App\Http\Resources\Wishlist;

use App\Models\Wishlist;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Pagination\LengthAwarePaginator;

class WishlistResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            Wishlist::ID => $this->id,
            Wishlist::NAME => $this->name,
            Wishlist::IS_DEFAULT => $this->is_default,
            'variants_count' => $this->whenCounted('variants'),
            'created_at' => optional($this->created_at)->format('Y-m-d H:i:s'),
            'updated_at' => optional($this->updated_at)->format('Y-m-d H:i:s'),
            'variants' => VariantInWishlistResource::collection($this->whenLoaded('variants')),
            'pagination' => $this->when(
                $this->relationLoaded('variants') && $this->resource->variants instanceof LengthAwarePaginator,
                function () {
                    return [
                        'current_page' => $this->resource->variants->currentPage(),
                        'last_page' => $this->resource->variants->lastPage(),
                        'per_page' => $this->resource->variants->perPage(),
                        'total' => $this->resource->variants->total(),
                    ];
                }
            ),
        ];
    }
}
