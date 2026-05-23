<?php

namespace App\Http\Resources\Profile;

use App\Models\EmployerConsultant;
use App\Models\User;
use App\Models\UserProfile;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ConsultantRelationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $status = $this->pivot->{EmployerConsultant::STATUS};

        $statusText = match ($status) {
            EmployerConsultant::STATUS_PENDING => 'در انتظار تایید',
            EmployerConsultant::STATUS_APPROVED => 'تایید شده',
            EmployerConsultant::STATUS_REJECTED => 'رد شده',
            default => $status,
        };

        return [
            'user_id' => $this->id,
            'name' => $this->whenLoaded('profile', $this->profile->{UserProfile::NAME} ?? ''),
            'family' => $this->whenLoaded('profile', $this->profile->{UserProfile::FAMILY} ?? ''),
            User::MOBILE => $this->{User::MOBILE},
            User::NATIONAL_CODE => $this->{User::NATIONAL_CODE},

            'status' => $statusText,
            'status_code' => $status,
            'reject_reason' => $this->when(
                $status === EmployerConsultant::STATUS_REJECTED,
                $this->pivot->{EmployerConsultant::REJECT_REASON}
            ),
        ];
    }
}
