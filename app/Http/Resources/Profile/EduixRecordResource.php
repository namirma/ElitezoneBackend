<?php

namespace App\Http\Resources\Profile;

use App\Http\Resources\BaseJsonResource;
use App\Models\EduixRecord;
use Carbon\Carbon;
use Illuminate\Http\Request;

/**
 * @mixin EduixRecord
 */
class EduixRecordResource extends BaseJsonResource
{
    public function toArray(Request $request): array
    {
        $statusText = match ($this->{EduixRecord::ADMIN_STATUS}) {
            EduixRecord::STATUS_PENDING => 'در انتظار تایید',
            EduixRecord::STATUS_APPROVED => 'تایید شده',
            EduixRecord::STATUS_REJECTED => 'رد شده',
            default => $this->{EduixRecord::ADMIN_STATUS},
        };

        return [
            EduixRecord::ID => $this->id,
            EduixRecord::NAME => $this->name,
            EduixRecord::FAMILY => $this->family,
            EduixRecord::FATHER_NAME => $this->father_name,
            EduixRecord::ID_NUMBER => $this->id_number,
            EduixRecord::BIRTH_DATE => $this->birth_date,
            EduixRecord::UNIVERSITY => $this->university,
            EduixRecord::FIELD => $this->field,
            EduixRecord::COURSE_TYPE => $this->course_type,
            EduixRecord::ENTRY_DATE => $this->entry_date,
            EduixRecord::GRADUATE_DATE => $this->graduate_date,
            EduixRecord::GPA => $this->gpa,
            EduixRecord::STATUS => $this->status,
            'admin_status' => $statusText,
            'admin_status_code' => $this->{EduixRecord::ADMIN_STATUS},
            EduixRecord::REJECTION_REASON => $this->{EduixRecord::REJECTION_REASON},
            'retrieved_at' => Carbon::parse($this->updated_at)->format('Y-m-d H:i:s'),
        ];
    }
}
