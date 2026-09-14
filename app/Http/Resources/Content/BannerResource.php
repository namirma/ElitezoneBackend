<?php

namespace App\Http\Resources\Content;

use App\Http\Resources\BaseJsonResource;
use App\Models\Banner;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * @mixin Banner
 */
class BannerResource extends BaseJsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            Banner::ID => $this->id,
            Banner::TITLE => $this->title,
            Banner::SUBTITLE => $this->subtitle,
            Banner::IMAGE => $this->image
                ? (str_starts_with($this->image, 'http') ? $this->image : Storage::disk('public')->url($this->image))
                : null,
            Banner::LINK => $this->link,
            Banner::POSITION => $this->position,
            Banner::CATEGORY_ID => $this->category_id,
            Banner::SORT_ORDER => $this->sort_order,
            Banner::IS_ACTIVE => $this->is_active,
            Banner::CREATED_AT => $this->created_at,
            'created_at_p' => $this->created_at_p,
        ];
    }
}
