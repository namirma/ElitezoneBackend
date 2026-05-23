<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validation for the admin back-office user lookup endpoint.
 * Accepts a mobile number, national code, or national ID as search keyword.
 */
class UserBackOfficeSearchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // دسترسی توسط middleware role:admin در route کنترل می‌شود
    }

    public function rules(): array
    {
        return [
            'keyword' => ['required', 'string', 'min:3'],
        ];
    }

    public function messages(): array
    {
        return [
            'keyword.required' => 'کلیدواژه جستجو الزامی است.',
            'keyword.min'      => 'کلیدواژه باید حداقل ۳ کاراکتر باشد.',
        ];
    }
}
