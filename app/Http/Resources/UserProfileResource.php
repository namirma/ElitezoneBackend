<?php

namespace App\Http\Resources;

use App\Models\UserProfile;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserProfileResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            UserProfile::NAME => $this->name,
            UserProfile::FAMILY => $this->family,
            UserProfile::EMAIL => $this->email,
            UserProfile::EMAIL_VERIFIED_AT => $this->email_verified_at,
            UserProfile::NATIONAL_CODE => $this->national_code,
            UserProfile::NATIONAL_ID => $this->national_id,
            UserProfile::BIRTHDAY => $this->birthday,
            UserProfile::COMPANY => $this->company,
            UserProfile::ECONOMIC_CODE => $this->economic_code,
            UserProfile::REGISTER_ID => $this->register_id,
            UserProfile::PHONE => $this->phone,
            UserProfile::PROVINCE_ID => $this->province_id,
            UserProfile::CITY_ID => $this->city_id,
            UserProfile::ENTITY => $this->entity,
            UserProfile::CREATED_AT => $this->created_at_,
            'created_at_p' => $this->created_at_p,
            'created_at_diff' => $this->created_at_diff,
            'user' => UserResource::collection($this->whenLoaded('profile')),
        ];
    }
}
