<?php

namespace App\Http\Requests\Profile;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

class InviteConsultantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'mobile' => ['nullable', 'required_without:national_code', 'string', 'regex:/^(\+?98|0098|0)?9\d{9}$/'],
            'national_code' => ['nullable', 'required_without:mobile', 'string', 'regex:/^\d{10}$/'],
        ];
    }

    public function messages(): array
    {
        return [
            User::MOBILE.'.required_without' => __('auth.mobile_or_national_code_required'),
            User::NATIONAL_CODE.'.required_without' => __('auth.mobile_or_national_code_required'),
        ];
    }
}
