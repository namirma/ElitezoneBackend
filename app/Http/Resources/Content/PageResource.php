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
            // مصرف‌کننده باید بداند این صفحه بدنه‌ی متنی دارد یا از بلوک ساخته
            // می‌شود — وگرنه `/p/{slug}` یک صفحه‌ی خالی رندر می‌کند.
            Page::HAS_BLOCKS => (bool) $this->has_blocks,
            Page::STYLE => $this->style ?: [],
            Page::ROUTE => $this->route ?: '/p/'.$this->slug,
            Page::CREATED_AT => $this->created_at,
            Page::UPDATED_AT => $this->updated_at,
        ];
    }
}
