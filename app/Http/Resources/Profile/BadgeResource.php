<?php

namespace App\Http\Resources\Profile;

use App\Models\Badge;
use Illuminate\Http\Request;
use App\Http\Resources\BaseJsonResource;

class BadgeResource extends BaseJsonResource
{
    public function toArray(Request $request): array
    {
        return [
            Badge::ID => $this->id,
            Badge::NAME => $this->name,
            Badge::CODE => $this->code,
            Badge::ICON => $this->icon,
            Badge::CREATED_AT => $this->created_at?->format('Y-m-d H:i:s'),
            Badge::UPDATED_AT => $this->updated_at?->format('Y-m-d H:i:s'),
        ];
    }
}
