<?php

declare(strict_types=1);

namespace App\Http\Requests\Order;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;

/**
 * پیشنهادِ تمدیدِ رزرو توسط تأمین‌کننده — پرسش ۳ مدیریت.
 *
 * فقط «چند ساعت». مهلتِ مطلق را اکشن از روی مهلتِ فعلیِ سفارش می‌سازد (زیر
 * قفل)، و «آیا الان وقتِ پیشنهاد هست» هم همان‌جا سنجیده می‌شود — این‌جا فقط
 * شکلِ ورودی.
 */
class ProposeReserveExtensionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Auth::check();
    }

    public function rules(): array
    {
        return [
            // ۳۰ روز سقفِ یک پیشنهاد؛ «نامحدود» از تکرارِ پیشنهادها می‌آید، نه از یک عددِ بی‌سقف.
            'hours' => ['required', 'integer', 'min:1', 'max:720'],
        ];
    }

    public function messages(): array
    {
        return [
            'hours.required' => __('orders.extension.hours_required'),
            'hours.integer' => __('orders.extension.hours_invalid'),
            'hours.min' => __('orders.extension.hours_invalid'),
            'hours.max' => __('orders.extension.hours_too_long'),
        ];
    }
}
