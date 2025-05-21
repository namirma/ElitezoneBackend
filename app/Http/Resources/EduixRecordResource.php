<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use App\Models\EduixRecord;
use Carbon\Carbon;

class EduixRecordResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            EduixRecord::NAME          => $this->name,
            EduixRecord::FAMILY        => $this->family,
            EduixRecord::FATHER_NAME   => $this->father_name,
            EduixRecord::ID_NUMBER     => $this->id_number,
            EduixRecord::BIRTH_DATE    => $this->birth_date,
            EduixRecord::UNIVERSITY    => $this->university,
            EduixRecord::FIELD         => $this->field,
            EduixRecord::COURSE_TYPE   => $this->course_type,
            EduixRecord::ENTRY_DATE    => $this->entry_date,
            EduixRecord::GRADUATE_DATE => $this->graduate_date,
            EduixRecord::GPA           => $this->gpa,
            EduixRecord::STATUS        => $this->status,
            'retrieved_at'             => Carbon::parse($this->updated_at)->format('Y-m-d H:i:s'),
        ];
    }
}
