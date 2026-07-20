<?php

namespace App\Http\Requests\Profile;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

class InviteEliteYARRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // فقط موبایل — national_code حذف شد (مورد ۲، بند ۵)
            User::MOBILE => ['required', 'string', 'regex:/^(\+?98|0098|0)?9\d{9}$/'],
        ];
    }

    public function messages(): array
    {
        return [
            User::MOBILE.'.required' => __('elite_yar.mobile_required'),
            User::MOBILE.'.regex'    => __('auth.mobile_format_invalid'),
        ];
    }
}
