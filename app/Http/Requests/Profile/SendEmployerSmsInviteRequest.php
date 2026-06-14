<?php

namespace App\Http\Requests\Profile;

use App\Models\EmployerSmsInvite;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class SendEmployerSmsInviteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'mobile' => [
                'required',
                'string',
                'regex:/^09\d{9}$/',
                Rule::notIn([(string) Auth::user()->mobile]),
                Rule::unique('employer_sms_invites', EmployerSmsInvite::MOBILE)
                    ->where(EmployerSmsInvite::ADVISOR_ID, Auth::id()),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'mobile.required' => __('profile.invite_mobile_required'),
            'mobile.regex' => __('profile.invite_mobile_invalid'),
            'mobile.not_in' => __('profile.invite_mobile_self'),
            'mobile.unique' => __('profile.invite_already_sent'),
        ];
    }
}
