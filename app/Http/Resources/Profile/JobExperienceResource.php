<?php

namespace App\Http\Resources\Profile;

use App\Http\Resources\BaseJsonResource;
use App\Models\JobExperience;
use App\Support\JalaliDate;
use Illuminate\Http\Request;

/**
 * @mixin JobExperience
 */
class JobExperienceResource extends BaseJsonResource
{
    public function toArray(Request $request): array
    {
        $statusText = match ($this->{JobExperience::STATUS}) {
            JobExperience::STATUS_PENDING => 'در انتظار تایید',
            JobExperience::STATUS_APPROVED => 'تایید شده',
            JobExperience::STATUS_REJECTED => 'رد شده',
            default => $this->{JobExperience::STATUS},
        };

        return [
            JobExperience::ID => $this->id,
            JobExperience::TITLE => $this->{JobExperience::TITLE},
            JobExperience::ORGANIZATION_NAME => $this->{JobExperience::ORGANIZATION_NAME},
            JobExperience::PROJECT_ADDRESS => $this->{JobExperience::PROJECT_ADDRESS},
            JobExperience::FILE_PATH => $this->{JobExperience::FILE_PATH}
                ? (str_starts_with($this->{JobExperience::FILE_PATH}, 'http')
                    ? $this->{JobExperience::FILE_PATH}
                    : route('job-experience.file', $this->id))
                : null,
            JobExperience::START_DATE => $this->{JobExperience::START_DATE}?->format('Y/m/d'),
            JobExperience::END_DATE => $this->{JobExperience::END_DATE}?->format('Y/m/d'),
            'status' => $statusText,
            'status_code' => $this->{JobExperience::STATUS},
            JobExperience::REJECTION_REASON => $this->{JobExperience::REJECTION_REASON},
            'created_at_p' => $this->created_at ? JalaliDate::format($this->created_at, 'Y/m/d') : null,
        ];
    }
}
