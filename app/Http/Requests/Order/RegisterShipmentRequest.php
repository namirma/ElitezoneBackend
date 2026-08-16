<?php

namespace App\Http\Requests\Order;

use Illuminate\Foundation\Http\FormRequest;

/**
 * بارنامه + راننده + وزن باسکول، در یک درخواست (فاز ۷، بند ۳.۷).
 *
 * دسترسی در کنترلر با `authorize('manageAsSupplier', $order)` بررسی می‌شود —
 * همان گاردی که بقیه‌ی اقدام‌های تأمین‌کننده دارند.
 */
class RegisterShipmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'waybill_ref' => ['required', 'string', 'max:100'],
            'driver_name' => ['required', 'string', 'max:150'],
            // کد ملی و موبایل اختیاری‌اند: بارنامه‌ی کاغذی همیشه هر دو را ندارد و
            // نبودشان نباید جلوی ثبتِ حمل را بگیرد. ولی اگر آمدند، باید درست باشند.
            'driver_national_code' => ['nullable', 'string', 'digits:10'],
            'driver_mobile' => ['nullable', 'string', 'regex:/^09\d{9}$/'],
            'vehicle_plate' => ['required', 'string', 'max:30'],
            'vehicle_type' => ['nullable', 'string', 'max:100'],
            // وزنِ واقعیِ باسکول. `min:1` چون صفر یعنی داده‌ی خراب، نه «بارِ خالی» —
            // و تسویه روی وزنِ صفر کلِ مبلغ را به خریدار عودت می‌دهد.
            'actual_weight' => ['required', 'numeric', 'min:1'],
        ];
    }

    public function messages(): array
    {
        return [
            'waybill_ref.required' => __('shipments.waybill_ref_required'),
            'driver_name.required' => __('shipments.driver_name_required'),
            'driver_national_code.digits' => __('shipments.driver_national_code_invalid'),
            'driver_mobile.regex' => __('shipments.driver_mobile_invalid'),
            'vehicle_plate.required' => __('shipments.vehicle_plate_required'),
            'actual_weight.required' => __('shipments.actual_weight_required'),
            'actual_weight.min' => __('shipments.actual_weight_min'),
        ];
    }
}
