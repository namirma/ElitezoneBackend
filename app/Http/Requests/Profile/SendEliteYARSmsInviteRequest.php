<?php

namespace App\Http\Requests\Profile;

use App\Http\Requests\Concerns\ReadsScalarInput;
use App\Models\EliteYARSmsInvite;
use App\Support\PhoneNumber;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

/**
 * قرینه‌ی `SendEmployerSmsInviteRequest` برای سمتِ کارفرما.
 *
 * تفاوتِ عمدی: شماره **پیش از** اعتبارسنجی نرمال می‌شود. قاعده‌ی سراسری پروژه
 * `PhoneNumber::to09()` است، و بدون آن یک شماره‌ی `+98…` هم از `regex:/^09/`
 * رد می‌شد و هم می‌توانست دوباره به‌عنوان یک دعوتِ «تازه» ثبت شود و سهمیه را
 * دو بار بخورد.
 */
class SendEliteYARSmsInviteRequest extends FormRequest
{
    use ReadsScalarInput;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->filled('mobile')) {
            $this->merge(['mobile' => PhoneNumber::to09($this->scalarInput('mobile'))]);
        }
    }

    public function rules(): array
    {
        return [
            'mobile' => [
                'required',
                'string',
                'regex:/^09\d{9}$/',
                Rule::notIn([(string) Auth::user()->mobile]),
                Rule::unique('elite_yar_sms_invites', EliteYARSmsInvite::MOBILE)
                    ->where(EliteYARSmsInvite::EMPLOYER_ID, Auth::id()),
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
