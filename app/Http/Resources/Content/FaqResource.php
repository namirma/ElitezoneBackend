<?php

namespace App\Http\Resources\Content;

use App\Http\Resources\BaseJsonResource;
use App\Models\Faq;
use Illuminate\Http\Request;

/**
 * @mixin Faq
 */
class FaqResource extends BaseJsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            Faq::ID => $this->id,
            Faq::FAQ_CATEGORY_ID => $this->faq_category_id,
            Faq::QUESTION => $this->question,
            Faq::ANSWER => $this->answer,
            Faq::CATEGORY => $this->category,
            Faq::SORT_ORDER => $this->sort_order,
            Faq::IS_ACTIVE => $this->is_active,
            Faq::CREATED_AT => $this->created_at,
        ];
    }
}
