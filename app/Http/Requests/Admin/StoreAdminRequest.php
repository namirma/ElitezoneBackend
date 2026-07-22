<?php

namespace App\Http\Requests\Admin;

use App\Models\User;
use App\Support\PhoneNumber;
use Database\Seeders\AdminPermissionSeeder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * ساخت مدیر سیستم جدید (کاربر + نقش admin).
 */
class StoreAdminRequest extends FormRequest
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
        return [
            User::MOBILE => ['required', 'regex:/^09\d{9}$/', 'unique:users,mobile'],
            User::PASSWORD => ['required', 'string', 'min:8'],
            'name' => ['nullable', 'string', 'max:255'],
            'family' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            // سطح دسترسی — بدون ارسال، همه مجوزها داده می‌شود
            'permissions' => ['nullable', 'array'],
            'permissions.*' => ['string', Rule::in(array_keys(AdminPermissionSeeder::PERMISSIONS))],
        ];
    }

    public function messages(): array
    {
        return [
            'mobile.required' => __('admin.admins.mobile_required'),
            'mobile.regex' => __('admin.admins.mobile_invalid'),
            'mobile.unique' => __('admin.admins.mobile_unique'),
            'password.required' => __('admin.admins.password_required'),
            'password.min' => __('admin.admins.password_min'),
        ];
    }
}
