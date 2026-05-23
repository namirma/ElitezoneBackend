<?php

namespace App\Http\Resources\Profile;

use App\Models\EmployerProfile;
use Illuminate\Http\Request;
use App\Http\Resources\BaseJsonResource;

class EmployerProfileResource extends BaseJsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            EmployerProfile::PROJECT_DONE => $this->project_done,
            EmployerProfile::PROJECT_IN_PROGRESS => $this->project_in_progress,
            EmployerProfile::ACTIVITY_PROVINCES => $this->activity_provinces,
        ];
    }
}
