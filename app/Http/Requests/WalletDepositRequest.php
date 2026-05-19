<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class WalletDepositRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $rules = [
            'amount' => [
                'required',
                'integer',
                'min:10000',
            ],
            'payment_method' => [
                'required',
                'string',
                Rule::in(['podium', 'shaparak', 'bank_transfer']),
            ],
        ];
        $method = $this->input('payment_method');

        if ($method === 'shaparak') {
            $rules['amount'][] = 'max:200000000';
        } elseif ($method === 'podium') {
            $rules['amount'][] = 'max:2000000000';
        }

        return $rules;
    }

    public function messages(): array
    {
        $method = $this->input('payment_method');
        $maxMessage = '';
        if ($method === 'shaparak') {
            $maxMessage = __('auth.deposit_amount_max_shaparak');
        } elseif ($method === 'podium') {
            $maxMessage = __('auth.deposit_amount_max_podium');
        }

        return [
            'amount.min' => __('auth.deposit_amount_min', ['min' => number_format(10000)]),
            'amount.max' => $maxMessage,
            'payment_method.in' => __('auth.payment_method_invalid'),
        ];
    }
}
