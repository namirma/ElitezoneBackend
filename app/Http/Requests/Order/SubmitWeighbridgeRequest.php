<?php

namespace App\Http\Requests\Order;

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
            'weight_after_scale.required' => __('orders.weighbridge_weight_required'),
            'weight_after_scale.numeric' => __('orders.weighbridge_weight_numeric'),
            'weight_after_scale.min' => __('orders.weighbridge_weight_min'),
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'weight_after_scale' => (float) $this->weight_after_scale,
        ]);
    }
}
