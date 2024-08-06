<?php

namespace App\Http\Requests;

use App\Models\ContractorProfile;
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
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array|string>
     */
    public function rules(): array
    {
        return [
            ContractorProfile::ENGINEER_MEMBER => 'boolean',
            ContractorProfile::COMPANY_NAME => 'string',
            ContractorProfile::NOT_RELATED => 'boolean',
            ContractorProfile::LICENSE_NUMBER => 'string',
            ContractorProfile::SUPERVISION_BASE => 'string',
            ContractorProfile::DESIGN_BASE => 'string',
            ContractorProfile::ACTIVITY_HISTORY => 'integer',
            ContractorProfile::ACTIVITY_PROVINCES => 'string',
            ContractorProfile::PROJECT_DONE => 'string',
            ContractorProfile::PROJECT_IN_PROGRESS => 'string',
            ContractorProfile::TRUSTED_CONTRACTOR => 'boolean',
        ];
    }
}
