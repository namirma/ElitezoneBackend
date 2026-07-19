<?php

namespace App\Http\Requests\Admin;

use App\Models\User;
use App\Support\PhoneNumber;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * ویرایش مدیر سیستم — password اختیاری (فقط هنگام تغییر ارسال می‌شود).
 */
class UpdateAdminRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->filled(User::MOBILE)) {
            $this->merge([User::MOBILE => PhoneNumber::to09((string) $this->input(User::MOBILE))]);
        }
    }

    public function rules(): array
    {
        $admin = $this->route('user');

        return [
            User::MOBILE => [
                'sometimes', 'required', 'regex:/^09\d{9}$/',
                Rule::unique('users', 'mobile')->ignore($admin?->id),
            ],
            User::PASSWORD => ['nullable', 'string', 'min:8'],
            'name' => ['nullable', 'string', 'max:255'],
            'family' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'mobile.required' => __('admin.admins.mobile_required'),
            'mobile.regex' => __('admin.admins.mobile_invalid'),
            'mobile.unique' => __('admin.admins.mobile_unique'),
            'password.min' => __('admin.admins.password_min'),
        ];
    }
}
