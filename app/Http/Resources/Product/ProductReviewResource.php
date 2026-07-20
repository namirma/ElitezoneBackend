<?php

namespace App\Http\Resources\Product;

use App\Http\Resources\BaseJsonResource;
use App\Models\ProductReview;
use Illuminate\Http\Request;

/**
 * @mixin ProductReview
 */
class ProductReviewResource extends BaseJsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            ProductReview::ID => $this->id,
            ProductReview::RATING => $this->rating,
            ProductReview::COMMENT => $this->comment,
            ProductReview::STATUS => $this->status,
            ProductReview::REJECTION_REASON => $this->rejection_reason,
            'user' => $this->whenLoaded('user', fn () => [
                'id' => $this->user->id,
                'mobile' => $this->user->mobile,
                'name' => trim(($this->user->profile?->name ?? '').' '.($this->user->profile?->family ?? '')) ?: null,
            ]),
            'product' => $this->whenLoaded('product', fn () => [
                'id' => $this->product->id,
                'name' => $this->product->name,
            ]),
            ProductReview::CREATED_AT => $this->created_at,
            'created_at_p' => $this->created_at_p,
        ];
    }
}
