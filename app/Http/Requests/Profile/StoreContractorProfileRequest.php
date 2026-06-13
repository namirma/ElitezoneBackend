<?php

namespace App\Http\Requests\Profile;

use App\Models\ContractorProfile;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreContractorProfileRequest extends FormRequest
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
            ContractorProfile::ENGINEER_MEMBER => 'boolean',
            ContractorProfile::COMPANY_NAME => 'nullable|string|max:255',
            ContractorProfile::NOT_RELATED => 'boolean',
            ContractorProfile::LICENSE_NUMBER => 'nullable|string|max:100',
            ContractorProfile::SUPERVISION_BASE => 'nullable|string|max:100',
            ContractorProfile::DESIGN_BASE => 'nullable|string|max:100',
            ContractorProfile::ACTIVITY_HISTORY => 'nullable|integer|min:0|max:120',
            ContractorProfile::ACTIVITY_PROVINCES => 'nullable|string|max:1000',
            ContractorProfile::PROJECT_DONE => 'nullable|string|max:5000',
            ContractorProfile::PROJECT_IN_PROGRESS => 'nullable|string|max:5000',
            ContractorProfile::TRUSTED_CONTRACTOR => 'boolean',
        ];
    }
}
