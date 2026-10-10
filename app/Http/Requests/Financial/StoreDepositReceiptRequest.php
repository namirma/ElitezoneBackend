<?php

namespace App\Http\Requests\Financial;

use Illuminate\Foundation\Http\FormRequest;

class StoreDepositReceiptRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'amount' => 'required|integer|min:10000',
            'receipt_number' => 'required|string|max:50',
            'payment_date' => 'required|string',
            'bank_name' => 'required|string|max:100',
            'description' => 'nullable|string|max:500',
            'receipt_image' => 'required|file|mimes:jpg,jpeg,png,pdf|max:5120',
        ];
    }

    public function messages(): array
    {
        $min = (int) config('wallet.min_receipt_amount', 10000);

        return [
            'amount.required' => 'مبلغ الزامی است.',
            'amount.integer' => 'مبلغ باید عدد صحیح باشد.',
            // مبلغ‌ها ریال‌اند؛ این پیام «تومان» می‌گفت (۱۰ برابر کمتر از حدِ واقعی).
            'amount.min' => __('auth.deposit_amount_min', ['min' => number_format($min)]),

            'receipt_number.required' => 'شماره رسید الزامی است.',
            'bank_name.required' => 'نام بانک الزامی است.',
            'payment_date.required' => 'تاریخ پرداخت الزامی است.',

            'receipt_image.required' => 'تصویر رسید الزامی است.',
            'receipt_image.mimes' => 'فرمت فایل باید jpg/jpeg/png/pdf باشد.',
            'receipt_image.max' => 'حجم فایل نباید بیشتر از ۵ مگابایت باشد.',
        ];
    }
}
