<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use App\Models\Order;

class PayHoldFeeRequest extends FormRequest
{
    public function authorize(): bool
    {
        $order = $this->route('order');
        return $order && $order->{Order::PLACER_USER_ID} === Auth::id();
    }

    /**
     * قوانین اعتبارسنجی مبلغ پیش‌پرداخت.
     */
    public function rules(): array
    {
        return [

            'amount' => ['required', 'integer', 'min:50000000', 'max:2000000000'],
        ];
    }

    public function messages(): array
    {
        return [
            'amount.min' => 'حداقل مبلغ برای تمدید رزرو ۱۰,۰۰۰ تومان است.',
            'amount.max' => 'حداکثر مبلغ برای تمدید رزرو ۲۰۰ میلیون تومان است.',
        ];
    }
}
