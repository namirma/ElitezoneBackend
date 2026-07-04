<?php

namespace App\Http\Requests\Profile;

use App\Models\EmployerProfile;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreEmployerProfileRequest extends FormRequest
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
            EmployerProfile::PROJECT_DONE => 'nullable|string|max:255',
            EmployerProfile::PROJECT_IN_PROGRESS => 'nullable|string|max:255',
            EmployerProfile::ACTIVITY_PROVINCES => 'nullable|string|max:128',
        ];
    }
}
