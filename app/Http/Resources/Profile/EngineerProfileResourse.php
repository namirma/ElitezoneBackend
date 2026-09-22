<?php

namespace App\Http\Resources\Profile;

use App\Http\Resources\BaseJsonResource;
use App\Models\EngineerProfile;
use Illuminate\Http\Request;

/**
 * @mixin EngineerProfile
 */
class EngineerProfileResourse extends BaseJsonResource
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
            EngineerProfile::IS_SAZE90_USER => (bool) $this->is_saze90_user,
            // پرچمِ خودِ ستون: مدارک توسط ادمین تأیید شده (دکمه‌ی «تأیید مدارک»). حکمِ
            // نهاییِ «الیت یارِ تأییدشده» فقط در ریشه‌ی پروفایل (is_verified) است.
            EngineerProfile::IS_VERIFIED => (bool) $this->is_verified,
            EngineerProfile::VERIFIED_AT => $this->verified_at,
            EngineerProfile::LICENSE_MEMBER => $this->license_number,
            EngineerProfile::SUPERVISION_BASE => $this->supervision_base,
            EngineerProfile::DESIGN_BASE => $this->design_base,
            EngineerProfile::MEMBER_NUMBER => $this->member_number,
            EngineerProfile::FIELDS_OF_STUDY => $this->fields_of_study,
            EngineerProfile::STUDENT => $this->student,
            EngineerProfile::PROVINCE_ID => $this->province_id,
            EngineerProfile::ACTIVITY_PROVINCE_ID => $this->activity_province_id,
            EngineerProfile::CITY_ID => $this->city_id,
            EngineerProfile::ACTIVITY_CITY_ID => $this->activity_city_id,
            EngineerProfile::UNIVERSITY_NAME => $this->university_name,
            EngineerProfile::CREATED_AT => $this->created_at,
            'created_at_p' => $this->created_at_p,
            'created_at_diff' => $this->created_at_diff,
        ];
    }
}
