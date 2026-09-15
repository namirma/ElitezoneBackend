<?php

namespace App\Http\Requests\Admin;

use App\Support\PhoneNumber;
use Illuminate\Foundation\Http\FormRequest;

/**
 * ارسالِ آزمایشیِ یک قالب به یک شماره. پارامترهای نمونه اختیاری‌اند — جانگهدارِ
 * بی‌مقدار در متن با ««نام»» پر می‌شود تا ادمین جای هر مقدار را ببیند.
 */
class TestSmsTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $mobile = $this->input('mobile');

        if (is_string($mobile) && $mobile !== '') {
            $this->merge(['mobile' => PhoneNumber::to09($mobile)]);
        }
    }

    public function rules(): array
    {
        return [
            'mobile' => ['required', 'string', 'regex:/^09\d{9}$/'],
            'params' => ['nullable', 'array'],
            'params.*' => ['nullable', 'string', 'max:200'],
        ];
    }

    public function messages(): array
    {
        return [
            'mobile.required' => __('admin.sms.mobile_invalid'),
            'mobile.regex' => __('admin.sms.mobile_invalid'),
        ];
    }
}
