<?php

namespace App\Http\Requests;

use App\Models\EmployerProfile;
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
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array|string>
     */
    public function rules(): array
    {
        return [
            EmployerProfile::PROJECT_DONE => '',
            EmployerProfile::PROJECT_IN_PROGRESS => '',
            EmployerProfile::ACTIVITY_PROVINCES => '',
        ];
    }
}
