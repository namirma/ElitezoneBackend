<?php

namespace App\Http\Resources\Profile;

use App\Models\UserType;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserTypeResource extends JsonResource
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
