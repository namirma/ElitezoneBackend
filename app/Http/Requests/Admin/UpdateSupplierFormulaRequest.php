<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * بروزرسانی فلگ‌های فرمول کمیسیون سوپلایر
 *
 * trans_commission: آیا کمیسیون %OW روی هزینه حمل (Trans) اعمال شود؟
 * trans_vat: آیا مالیات بر ارزش افزوده روی هزینه حمل اعمال شود؟
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
            'trans_vat'        => ['required', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'trans_commission.required' => __('admin.suppliers.trans_commission_required'),
            'trans_vat.required'        => __('admin.suppliers.trans_vat_required'),
        ];
    }
}
