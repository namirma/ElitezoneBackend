<?php

namespace App\Http\Requests\Podium;

use Illuminate\Foundation\Http\FormRequest;

class MobileNidMatchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // protected by auth:sanctum + role:admin at route level
    }

    public function rules(): array
    {
        return [
            'national_code' => ['required', 'regex:/^\d{10}$/'],
            'mobile_number' => ['required', 'regex:/^(\+?98|0098|0)?9\d{9}$/'],
        ];
    }

    public function messages(): array
    {
        return [
            'national_code.required' => 'کد ملی الزامی است.',
            'national_code.regex' => 'کد ملی باید ۱۰ رقم باشد.',
            'mobile_number.required' => 'شماره موبایل الزامی است.',
            'mobile_number.regex' => 'فرمت شماره موبایل معتبر نیست.',
        ];
    }
}
