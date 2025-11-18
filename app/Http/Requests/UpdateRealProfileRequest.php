<?php

namespace App\Http\Requests;

use App\Models\UserProfile;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class UpdateRealProfileRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        $user = Auth::user();
        $isFixed = !empty($user->profile?->{UserProfile::NATIONAL_CODE});

        return [
            UserProfile::NAME => [$isFixed ? 'prohibited' : 'required', 'string', 'max:50'],
            UserProfile::FAMILY => [$isFixed ? 'prohibited' : 'required', 'string', 'max:50'],
            UserProfile::NATIONAL_CODE => [$isFixed ? 'prohibited' : 'required', 'digits:10'],
            UserProfile::BIRTHDAY => [$isFixed ? 'prohibited' : 'required', 'string'],
            UserProfile::EMAIL => [
                'nullable',
                'email:rfc,dns',
                Rule::unique('user_profiles', UserProfile::EMAIL)->ignore($user->profile?->id)
            ],
            UserProfile::PROVINCE_ID => ['nullable', 'exists:province,id'],
            UserProfile::CITY_ID => ['nullable', 'exists:province_city,id'],
        ];
    }

    public function messages(): array
    {
        return [
            'national_code.prohibited' => 'نام، نام خانوادگی و کد ملی پس از ثبت اولیه قابل تغییر نیستند.',
            'email.unique' => 'این ایمیل قبلا ثبت شده است.',
        ];
    }
}
