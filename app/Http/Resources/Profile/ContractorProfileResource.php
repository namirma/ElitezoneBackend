<?php

namespace App\Http\Resources\Profile;

use App\Models\ContractorProfile;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ContractorProfileResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            ContractorProfile::ENGINEER_MEMBER => $this->engineer_member,
            ContractorProfile::COMPANY_NAME => $this->company_name,
            ContractorProfile::NOT_RELATED => $this->not_related,
            ContractorProfile::LICENSE_NUMBER => $this->license_number,
            ContractorProfile::SUPERVISION_BASE => $this->supervision_base,
            ContractorProfile::DESIGN_BASE => $this->design_base,
            ContractorProfile::ACTIVITY_HISTORY => $this->activity_history,
            ContractorProfile::ACTIVITY_PROVINCES => $this->activity_provinces,
            ContractorProfile::PROJECT_DONE => $this->project_done,
            ContractorProfile::PROJECT_IN_PROGRESS => $this->project_in_progress,
            ContractorProfile::TRUSTED_CONTRACTOR => $this->trusted_contractor,
            ContractorProfile::CREATED_AT => $this->created_at_,
            'created_at_p' => $this->created_at_p,
            'created_at_diff' => $this->created_at_diff,
        ];
    }
}
