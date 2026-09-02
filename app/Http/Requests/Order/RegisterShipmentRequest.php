<?php

namespace App\Http\Requests\Order;

use App\Models\Driver;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;

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

            // ⚠️ دفترچه‌ی راننده تا امروز به هیچ‌جا وصل نبود.
            //
            // جدولِ `drivers` و ستونِ `shipments.driver_id` هر دو از فاز ۹ وجود
            // داشتند، تأمین‌کننده می‌توانست راننده ثبت و موبایلش را با OTP تأیید
            // کند — ولی **هیچ‌چیز در کلِ `app/` هرگز `driver_id` را نمی‌نوشت**.
            // این فرم راننده را نمی‌پذیرفت، پس هر بارنامه راننده را از نو تایپ
            // می‌کرد و `mobile_verified_at` هیچ اثری نداشت.
            //
            // اختیاری است تا مسیرِ متنیِ فعلی نشکند؛ ولی وقتی بیاید، اسنپ‌شاتِ
            // نام/کدملی/موبایل/پلاک از خودِ دفترچه پر می‌شود.
            'driver_id' => [
                'nullable',
                'integer',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    $ownsIt = Driver::query()
                        ->whereKey($value)
                        ->where(Driver::CARRIER_USER_ID, Auth::id())
                        ->where(Driver::IS_ACTIVE, true)
                        ->exists();

                    if (! $ownsIt) {
                        $fail(__('shipments.driver_not_in_book'));
                    }
                },
            ],

            // با انتخابِ راننده از دفترچه، تایپِ دوباره لازم نیست.
            'driver_name' => ['required_without:driver_id', 'string', 'max:150'],
            // کد ملی و موبایل اختیاری‌اند: بارنامه‌ی کاغذی همیشه هر دو را ندارد و
            // نبودشان نباید جلوی ثبتِ حمل را بگیرد. ولی اگر آمدند، باید درست باشند.
            'driver_national_code' => ['nullable', 'string', 'digits:10'],
            'driver_mobile' => ['nullable', 'string', 'regex:/^09\d{9}$/'],
            'vehicle_plate' => ['required_without:driver_id', 'string', 'max:30'],
            'vehicle_type' => ['nullable', 'string', 'max:100'],
            // وزنِ واقعیِ باسکول. `min:1` چون صفر یعنی داده‌ی خراب، نه «بارِ خالی» —
            // و تسویه روی وزنِ صفر کلِ مبلغ را به خریدار عودت می‌دهد.
            'actual_weight' => ['required', 'numeric', 'min:1'],
            // «این آخرین محموله بود» — ماشه‌ی تسویه‌ی نهایی (بند ۸.۲).
            // اختیاری چون مجموع‌رسیدن به وزن سفارش هم خودش ماشه است؛ ولی در
            // کم‌تحویلی تنها همین پرچم می‌تواند تسویه را شروع کند.
            'is_final' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'waybill_ref.required' => __('shipments.waybill_ref_required'),
            'driver_name.required_without' => __('shipments.driver_name_required'),
            'driver_national_code.digits' => __('shipments.driver_national_code_invalid'),
            'driver_mobile.regex' => __('shipments.driver_mobile_invalid'),
            'vehicle_plate.required_without' => __('shipments.vehicle_plate_required'),
            'actual_weight.required' => __('shipments.actual_weight_required'),
            'actual_weight.min' => __('shipments.actual_weight_min'),
        ];
    }
}
