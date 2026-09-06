<?php

declare(strict_types=1);

namespace App\Http\Requests\Order;

use Illuminate\Foundation\Http\FormRequest;

/**
 * اصلاحِ وزنِ باسکولِ یک محموله.
 *
 * دسترسی در کنترلر با `authorize('manageAsSupplier', $order)` سنجیده می‌شود —
 * همان گاردی که بقیه‌ی اقدام‌های تأمین‌کننده دارند.
 */
class CorrectShipmentWeightRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // همان `min:1` ثبتِ بارنامه: صفر یعنی داده‌ی خراب، نه «بارِ خالی» —
            // و تسویه روی وزنِ صفر کلِ مبلغ را به خریدار عودت می‌دهد.
            'actual_weight' => ['required', 'numeric', 'min:1'],
            'source' => ['sometimes', 'string', 'in:manual,ocr,api'],
        ];
    }

    public function messages(): array
    {
        return [
            'actual_weight.required' => __('shipments.actual_weight_required'),
            'actual_weight.min' => __('shipments.actual_weight_min'),
        ];
    }
}
