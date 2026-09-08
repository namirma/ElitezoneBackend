<?php

namespace App\Http\Resources\Content;

use App\Http\Resources\BaseJsonResource;
use App\Models\MenuItem;
use Illuminate\Http\Request;

/**
 * @mixin MenuItem
 */
class MenuItemResource extends BaseJsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            MenuItem::ID => $this->id,
            MenuItem::PARENT_ID => $this->parent_id,
            MenuItem::LOCATION => $this->location,
            MenuItem::TITLE => $this->title,
            // null یعنی «عنوان بدون لینک» — فرانت باید غیرقابل‌کلیک رندرش کند
            MenuItem::URL => $this->url,
            MenuItem::IS_HIGHLIGHTED => $this->is_highlighted,
            MenuItem::SORT_ORDER => $this->sort_order,
            MenuItem::IS_ACTIVE => $this->is_active,
            'children' => MenuItemResource::collection($this->whenLoaded('children')),
            MenuItem::CREATED_AT => $this->created_at,
        ];
    }
}
