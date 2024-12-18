<?php

namespace App\Http\Requests;

use App\Models\UserProfile;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class VerifyEmailRequest extends FormRequest
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
            UserProfile::EMAIL=>'required|email:rfc,dns',
        ];
    }
    public function messages(): array
    {
        return [
            UserProfile::EMAIL . '.email'  => 'لطفاً یک ایمیل معتبر وارد کنید.',
            UserProfile::EMAIL . '.required'  => 'وارد کردن ایمیل الزامی است',
        ];
    }
}
