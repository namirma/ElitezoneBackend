<?php

namespace App\Http\Requests;

use App\Models\EngineerProfile;
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
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array|string>
     */
    public function rules(): array
    {
        return [
            EngineerProfile::ENGINEER_MEMBER => 'boolean',
            EngineerProfile::LICENSE_MEMBER => '',
            EngineerProfile::SUPERVISION_BASE => '',
            EngineerProfile::DESIGN_BASE => '',
            EngineerProfile::MEMBER_NUMBER => '',
            EngineerProfile::FIELDS_OF_STUDY => '',
            EngineerProfile::STUDENT => 'boolean',
            EngineerProfile::UNIVERSITY_NAME => 'required_if:student,1',
            EngineerProfile::PROVINCE_ID => '',
            EngineerProfile::CITY_ID => ''
        ];
    }
}
