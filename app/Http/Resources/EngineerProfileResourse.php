<?php

namespace App\Http\Resources;

use App\Models\EngineerProfile;
use App\Models\UserProfile;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EngineerProfileResourse extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            EngineerProfile::ENGINEER_MEMBER => $this->engineer_member,
            EngineerProfile::LICENSE_MEMBER => $this->license_number,
            EngineerProfile::SUPERVISION_BASE => $this->supervision_base,
            EngineerProfile::DESIGN_BASE => $this->design_base,
            EngineerProfile::MEMBER_NUMBER => $this->member_number,
            EngineerProfile::FIELDS_OF_STUDY => $this->fields_of_study,
            EngineerProfile::STUDENT => $this->student,
            EngineerProfile::PROVINCE_ID => $this->province_id,
            EngineerProfile::CITY_ID => $this->city_id,
            EngineerProfile::UNIVERSITY_NAME => $this->university_name,
            EngineerProfile::CREATED_AT => $this->created_at_,
            'created_at_p' => $this->created_at_p,
            'created_at_diff' => $this->created_at_diff,
        ];
    }
}
