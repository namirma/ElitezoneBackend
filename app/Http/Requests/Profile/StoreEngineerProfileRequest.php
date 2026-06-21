<?php

namespace App\Http\Requests\Profile;

use App\Models\EngineerProfile;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreEngineerProfileRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array|string>
     */
    public function rules(): array
    {
        return [
            EngineerProfile::ENGINEER_MEMBER => 'boolean',
            EngineerProfile::IS_SAZE90_USER => 'boolean',
            EngineerProfile::LICENSE_MEMBER => 'nullable',
            EngineerProfile::SUPERVISION_BASE => 'nullable',
            EngineerProfile::DESIGN_BASE => 'nullable',
            EngineerProfile::MEMBER_NUMBER => 'nullable',
            EngineerProfile::FIELDS_OF_STUDY => 'nullable',
            EngineerProfile::STUDENT => 'boolean',
            EngineerProfile::UNIVERSITY_NAME => 'required_if:student,1',
            EngineerProfile::PROVINCE_ID => 'nullable|exists:province,id',
            EngineerProfile::ACTIVITY_PROVINCE_ID => 'nullable|exists:province,id',
            EngineerProfile::CITY_ID => 'nullable|exists:province_city,id',
            EngineerProfile::ACTIVITY_CITY_ID => 'nullable|exists:province_city,id',
        ];
    }
}
