<?php

namespace App\Http\Requests\Auth;

use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StorePasswordRequest extends FormRequest
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
            // حداقل طول ۸ — هم‌تراز با StoreAdminRequest. قبلاً فقط
            // 'required|confirmed' بود، یعنی کاربر عادی می‌توانست رمز تک‌کاراکتری
            // بگذارد در حالی که ادمین min:8 داشت.
            User::PASSWORD => ['required', 'string', 'min:8', 'confirmed'],
            // کد OTP مرحله اول (changePasswordFirstAttempt) — بدون آن، یک توکن
            // دزدیده‌شده برای تصاحب کامل حساب (تغییر رمز) کافی بود.
            'code' => 'required|digits:6',
        ];
    }

    public function messages(): array
    {
        return [
            'password.required' => __('auth.password_required'),
            'password.min' => __('auth.password_min'),
            'password.confirmed' => __('auth.password_confirmed'),
            'code.required' => __('auth.code_exists'),
            'code.digits' => __('auth.code_digits'),
        ];
    }
}
