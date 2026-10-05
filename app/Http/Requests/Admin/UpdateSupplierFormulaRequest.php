<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * بروزرسانی فلگ‌های فرمول کمیسیون سوپلایر
 *
 * trans_commission: آیا کمیسیون %OW روی هزینه حمل (Trans) اعمال شود؟
 * trans_vat: آیا مالیات بر ارزش افزوده روی هزینه حمل اعمال شود؟
 * commission_percent: درصدِ کارمزدِ قراردادِ این تأمین‌کننده (پرسشِ ۴ مدیریت، ۱۴۰۵/۰۷/۱۳)؛
 *   خالی/null = همان مقدارِ سراسریِ «پارامترهای مالی». بازه همان بازه‌ی tc_percentage (۰ تا ۲۰).
 *
 * توجه: تغییر این مقادیر فقط روی سفارشات آینده اثر می‌گذارد.
 * سفارشات پرداخت‌شده مقادیر snapshot‌شده خودشان را نگه می‌دارند.
 */
class UpdateSupplierFormulaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'trans_commission' => ['required', 'boolean'],
            'trans_vat' => ['required', 'boolean'],
            'commission_percent' => ['nullable', 'numeric', 'min:0', 'max:20'],
        ];
    }

    public function messages(): array
    {
        return [
            'trans_commission.required' => __('admin.suppliers.trans_commission_required'),
            'trans_vat.required' => __('admin.suppliers.trans_vat_required'),
            'commission_percent.numeric' => __('admin.suppliers.commission_percent_invalid'),
            'commission_percent.min' => __('admin.suppliers.commission_percent_invalid'),
            'commission_percent.max' => __('admin.suppliers.commission_percent_invalid'),
        ];
    }
}
