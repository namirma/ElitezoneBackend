<?php

namespace App\Http\Resources\Admin;

use App\Http\Resources\BaseJsonResource;
use App\Models\EduixRecord;
use Illuminate\Http\Request;

/**
 * @mixin EduixRecord
 */
class AdminEduixResource extends BaseJsonResource
{
    public function toArray(Request $request): array
    {
        return [
            EduixRecord::ID => $this->id,
            EduixRecord::NATIONAL_CODE => $this->{EduixRecord::NATIONAL_CODE},
            EduixRecord::NAME => $this->{EduixRecord::NAME},
            EduixRecord::FAMILY => $this->{EduixRecord::FAMILY},
            EduixRecord::FATHER_NAME => $this->{EduixRecord::FATHER_NAME},
            EduixRecord::UNIVERSITY => $this->{EduixRecord::UNIVERSITY},
            EduixRecord::FIELD => $this->{EduixRecord::FIELD},
            EduixRecord::COURSE_TYPE => $this->{EduixRecord::COURSE_TYPE},
            EduixRecord::ENTRY_DATE => $this->{EduixRecord::ENTRY_DATE},
            EduixRecord::GRADUATE_DATE => $this->{EduixRecord::GRADUATE_DATE},
            EduixRecord::GPA => $this->{EduixRecord::GPA},
            EduixRecord::TRACE_CODE => $this->{EduixRecord::TRACE_CODE},
            'admin_status' => $this->{EduixRecord::ADMIN_STATUS},
            EduixRecord::REJECTION_REASON => $this->{EduixRecord::REJECTION_REASON},
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
