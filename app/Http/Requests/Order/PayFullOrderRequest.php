<?php

namespace App\Http\Requests\Order;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PayFullOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'payment_type' => ['required', Rule::in(['pay', 'reserve'])],
        ];
    }

    public function messages(): array
    {
        return [
            'payment_type.required' => __('orders.payment_type_required'),
            'payment_type.in'       => __('orders.payment_type_invalid'),
        ];
    }
}
