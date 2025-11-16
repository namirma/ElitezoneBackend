<?php
namespace App\Http\Resources;

use App\Models\JobExperience;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class JobExperienceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $statusText = match($this->{JobExperience::STATUS}) {
            JobExperience::STATUS_PENDING => 'در انتظار تایید',
            JobExperience::STATUS_APPROVED => 'تایید شده',
            JobExperience::STATUS_REJECTED => 'رد شده',
            default => $this->{JobExperience::STATUS},
        };

        return [
            JobExperience::ID => $this->id,
            JobExperience::TITLE => $this->{JobExperience::TITLE},
            JobExperience::ORGANIZATION_NAME => $this->{JobExperience::ORGANIZATION_NAME},
            JobExperience::FILE_PATH => $this->{JobExperience::FILE_PATH},
            JobExperience::START_DATE => $this->{JobExperience::START_DATE}?->format('Y-m-d'),
            JobExperience::END_DATE => $this->{JobExperience::END_DATE}?->format('Y-m-d'),
            'status' => $statusText,
            'status_code' => $this->{JobExperience::STATUS},
            JobExperience::REJECTION_REASON => $this->{JobExperience::REJECTION_REASON},
            'created_at_p' => $this->created_at ? verta($this->created_at)->format('Y/m/d') : null,
        ];
    }
}
