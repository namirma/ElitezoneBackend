<?php

namespace App\Http\Requests\Auth;

use App\Support\PhoneNumber;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ChangeMobileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * دیتابیس موبایل را در فرم کانونیکال 09 نگه می‌دارد؛ رول unique باید روی همان
     * فرم اجرا شود — روی ورودی خام، فرم +98/98 همان شماره از یکتایی رد می‌شد و
     * confirmChangeMobile به برخورد unique index (پاسخ 500) می‌خورد.
     */
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('new_mobile'))) {
            $this->merge(['new_mobile' => PhoneNumber::to09($this->input('new_mobile'))]);
        }
    }

    public function rules(): array
    {
        return [
            'new_mobile' => ['required', 'regex:/^09\d{9}$/', Rule::unique('users', 'mobile')],
            'national_code' => ['required', 'digits:10'],
            'birth_date' => ['required', 'string'],
        ];
    }
}
