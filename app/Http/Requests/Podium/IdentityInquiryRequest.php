<?php

namespace App\Http\Requests\Podium;

use Illuminate\Foundation\Http\FormRequest;

class IdentityInquiryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // protected by auth:sanctum + role:admin at route level
    }

    public function rules(): array
    {
        return [
            'national_code' => ['required', 'regex:/^\d{10}$/'],
            'birth_date' => ['required', 'regex:/^(\d{8}|\d{4}-\d{2}-\d{2})$/'],
        ];
    }

    public function messages(): array
    {
        return [
            'national_code.required' => 'کد ملی الزامی است.',
            'national_code.regex' => 'کد ملی باید ۱۰ رقم باشد.',
            'birth_date.required' => 'تاریخ تولد الزامی است.',
            'birth_date.regex' => 'تاریخ تولد باید به فرمت YYYYMMDD یا YYYY-MM-DD باشد.',
        ];
    }
}
