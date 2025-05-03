<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use App\Models\Badge;

class BadgeResource extends JsonResource
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
