<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\Concerns\ChecksGrantableAdminLevel;
use App\Http\Requests\Concerns\ReadsScalarInput;
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
    use ChecksGrantableAdminLevel;
    use ReadsScalarInput;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->filled(User::MOBILE)) {
            $this->merge([User::MOBILE => PhoneNumber::to09($this->scalarInput(User::MOBILE))]);
        }
    }

    public function rules(): array
    {
        return [
            User::MOBILE => ['required', 'regex:/^09\d{9}$/', 'unique:users,mobile'],
            User::PASSWORD => ['required', 'string', 'min:8'],
            // ⚠️ الزامی‌اند و نباید اختیاری شوند: ورود با رمز در
            // `AuthController::checkPassword` هر حسابی را که
            // `users.national_code` یا `users.birth_date` خالی داشته باشد رد
            // می‌کند (`auth.profile_incomplete`). بدون این دو، مدیری که از پنل
            // ساخته می‌شد **هرگز نمی‌توانست وارد شود** — حساب ساخته می‌شد و
            // بی‌استفاده می‌ماند.
            User::NATIONAL_CODE => ['required', 'digits:10', 'unique:users,national_code'],
            User::BIRTH_DATE => ['required', 'regex:/^\d{8}$/'],
            'name' => ['nullable', 'string', 'max:255'],
            'family' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            // سطحِ اختیارِ مدیرِ تازه: ۱ مدیر ارشد، ۲ مدیر، ۳ کارشناس. الزامی و بی‌پیش‌فرض؛
            // سطحی بالاتر از سطحِ خودِ سازنده پذیرفته نمی‌شود. (تا ۲۰۲۶-۱۰-۰۳ نوشته نمی‌شد
            // و ادمینِ تازه روی هر مسیرِ `admin.level:N` ۴۰۳ می‌گرفت.)
            User::ADMIN_LEVEL => ['bail', 'required', 'integer', Rule::in(User::ADMIN_LEVELS), $this->withinGrantableLevels(...)],
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
            'national_code.required' => __('admin.admins.national_code_required'),
            'national_code.digits' => __('admin.admins.national_code_invalid'),
            'national_code.unique' => __('admin.admins.national_code_unique'),
            'birth_date.required' => __('admin.admins.birth_date_required'),
            'birth_date.regex' => __('admin.admins.birth_date_invalid'),
            'admin_level.required' => __('admin.levels.required'),
            'admin_level.integer' => __('admin.levels.invalid'),
            'admin_level.in' => __('admin.levels.invalid'),
        ];
    }
}
