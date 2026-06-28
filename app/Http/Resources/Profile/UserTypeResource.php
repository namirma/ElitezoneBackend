<?php

namespace App\Http\Resources\Profile;

use App\Http\Resources\BaseJsonResource;
use App\Models\UserType;
use Illuminate\Http\Request;

/**
 * @mixin UserType
 */
class UserTypeResource extends BaseJsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            UserType::ID => $this->id,
            UserType::NAME => $this->name,
            UserType::SLUG => $this->slug,
            UserType::ICON => $this->icon,
        ];
    }
}
