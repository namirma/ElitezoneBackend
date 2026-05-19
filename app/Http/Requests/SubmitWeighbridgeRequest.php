<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SubmitWeighbridgeRequest extends FormRequest
{
    /**
     * تعیین دسترسی کاربر
     */
    public function authorize(): bool
    {
        // اینجا می‌توانید چک کنید کاربر مجوز "ثبت باسکول" دارد یا خیر
        // فعلاً true می‌گذاریم چون در روت middleware داریم
        return true;
    }

    /**
     * قوانین اعتبارسنجی
     */
    public function rules(): array
    {
        return [
            'weight_after_scale' => ['required', 'numeric', 'min:1'],
        ];
    }

    /**
     * پیام‌های خطای فارسی
     */
    public function messages(): array
    {
        return [
            'weight_after_scale.required' => '.وارد کردن وزن نهایی باسکول الزامی است',
            'weight_after_scale.numeric' => 'وزن باسکول باید یک عدد معتبر باشد.',
            'weight_after_scale.min' => 'وزن باسکول نمی‌تواند کمتر از ۱ کیلوگرم باشد.',
        ];
    }

    /**
     * آماده‌سازی داده‌ها قبل از اعتبارسنجی (اختیاری)
     */
    protected function prepareForValidation()
    {
        $this->merge([
            'weight_after_scale' => (float) $this->weight_after_scale,
        ]);
    }
}
