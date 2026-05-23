<?php

namespace App\Http\Requests\Profile;

use App\Models\SupplierProfile;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * @property mixed $activity_category_id
 */
class StoreSupplierProfileRequest extends FormRequest
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
            SupplierProfile::ACTIVITY_DESCRIPTION => 'nullable',
            SupplierProfile::BRANDS => 'nullable',
            SupplierProfile::BRANDS_NOT_RELATED => 'boolean',
            SupplierProfile::ACTIVITY_HISTORY => 'nullable',
            SupplierProfile::ACTIVITY_PROVINCES => 'nullable',
            SupplierProfile::PRODUCTION_AMOUNT => 'nullable',
            SupplierProfile::STANDARDS => 'nullable',
            SupplierProfile::STANDARDS_NOT_RELATED => 'boolean',
            SupplierProfile::WEBSITE => 'string|nullable',
            SupplierProfile::WEBSITE_NOT_RELATED => 'boolean',
            SupplierProfile::COMPANY => 'string',
            'activity_category_id' => 'required|array',
            'activity_category_id.*' => 'exists:supplier_activity_categories,id',

        ];
    }
}
