<?php

namespace App\Http\Resources\Admin;

use App\Http\Resources\BaseJsonResource;
use App\Models\JobExperience;
use Illuminate\Http\Request;

/**
 * @mixin JobExperience
 */
class AdminJobExperienceResource extends BaseJsonResource
{
    public function toArray(Request $request): array
    {
        return [
            JobExperience::ID => $this->id,
            JobExperience::TITLE => $this->{JobExperience::TITLE},
            JobExperience::ORGANIZATION_NAME => $this->{JobExperience::ORGANIZATION_NAME},
            JobExperience::PROJECT_ADDRESS => $this->{JobExperience::PROJECT_ADDRESS},
            JobExperience::START_DATE => $this->{JobExperience::START_DATE}?->format('Y/m/d'),
            JobExperience::END_DATE => $this->{JobExperience::END_DATE}?->format('Y/m/d'),
            'status_code' => $this->{JobExperience::STATUS},
            JobExperience::REJECTION_REASON => $this->{JobExperience::REJECTION_REASON},
            // Admin-scoped download URL (the user route is owner-only and 403s for admins).
            'file_url' => $this->{JobExperience::FILE_PATH}
                ? (str_starts_with($this->{JobExperience::FILE_PATH}, 'http')
                    ? $this->{JobExperience::FILE_PATH}
                    : route('admin.job-experiences.file', $this->id))
                : null,
            'submitted_at' => $this->created_at ? verta($this->created_at)->format('Y/m/d H:i') : null,
            'waiting_days' => $this->created_at ? (int) $this->created_at->diffInDays(now()) : null,
            'user' => $this->whenLoaded('user', fn () => [
                'id' => $this->user->id,
                'mobile' => $this->user->mobile,
                'name' => $this->user->profile?->name,
            ]),
        ];
    }
}
