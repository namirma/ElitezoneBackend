<?php

namespace App\Http\Requests\Order;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;

/**
 * تمدید مهلت رزرو توسط تأمین‌کننده — بند ۳.۴.
 *
 * فقط «باید در آینده باشد» اینجاست؛ قاعده‌ی «فقط افزایشی» به مقدارِ فعلیِ
 * همان سفارش نیاز دارد و زیر قفل در `ExtendReserveDeadlineAction` سنجیده
 * می‌شود — جایی که یک تمدیدِ همزمان نتواند از کنارش رد شود.
 */
class ExtendReserveDeadlineRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Auth::check();
    }

    public function rules(): array
    {
        return [
            'reserve_deadline' => ['required', 'date', 'after:now'],
        ];
    }

    public function messages(): array
    {
        return [
            'reserve_deadline.required' => __('orders.reserve_deadline.required'),
            'reserve_deadline.date' => __('orders.reserve_deadline.invalid'),
            'reserve_deadline.after' => __('orders.reserve_deadline.must_be_future'),
        ];
    }
}
