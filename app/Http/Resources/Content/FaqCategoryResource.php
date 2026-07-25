<?php

namespace App\Http\Resources\Content;

use App\Http\Resources\BaseJsonResource;
use App\Models\FaqCategory;
use Illuminate\Http\Request;

/**
 * @mixin \App\Models\FaqCategory
 */
class FaqCategoryResource extends BaseJsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            FaqCategory::ID => $this->id,
            FaqCategory::TITLE => $this->title,
            FaqCategory::SLUG => $this->slug,
            FaqCategory::SUBTITLE => $this->subtitle,
            FaqCategory::SORT_ORDER => $this->sort_order,
            FaqCategory::IS_ACTIVE => $this->is_active,
            // فقط وقتی کنترلر عمداً لود کرده باشد (نمای عمومیِ تودرتوی کشوی پشتیبانی)
            'faqs' => FaqResource::collection($this->whenLoaded('faqs')),
            'faqs_count' => $this->whenCounted('faqs'),
            FaqCategory::CREATED_AT => $this->created_at,
        ];
    }
}
