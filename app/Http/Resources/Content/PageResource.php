<?php

namespace App\Http\Resources\Content;

use App\Http\Resources\BaseJsonResource;
use App\Models\Page;
use Illuminate\Http\Request;

/**
 * @mixin Page
 */
class PageResource extends BaseJsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            Page::ID => $this->id,
            Page::TITLE => $this->title,
            Page::SLUG => $this->slug,
            Page::BODY => $this->body,
            Page::IS_ACTIVE => $this->is_active,
            Page::CREATED_AT => $this->created_at,
            Page::UPDATED_AT => $this->updated_at,
        ];
    }
}
