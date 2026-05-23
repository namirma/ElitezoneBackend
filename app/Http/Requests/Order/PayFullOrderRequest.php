<?php

namespace App\Http\Requests\Order;

use Illuminate\Foundation\Http\FormRequest;

class PayFullOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'gateway' => 'required|in:wallet,shaparak,podium',
        ];
    }

    public function messages(): array
    {
        return [
            'gateway.required' => __('orders.gateway_required'),
            'gateway.in' => __('orders.gateway_invalid'),
        ];
    }
}
