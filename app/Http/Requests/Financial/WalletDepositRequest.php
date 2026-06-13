<?php

namespace App\Http\Requests\Financial;

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
                'min:'.(int) config('financial.deposit_min', 10_000),
            ],
            'payment_method' => [
                'required',
                'string',
                Rule::in(['podium', 'shaparak', 'bank_transfer']),
            ],
        ];
        $method = $this->input('payment_method');

        if ($method === 'shaparak') {
            $rules['amount'][] = 'max:'.(int) config('financial.deposit_max_shaparak', 200_000_000);
        } elseif ($method === 'podium') {
            $rules['amount'][] = 'max:'.(int) config('financial.deposit_max_podium', 2_000_000_000);
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
            'amount.min' => __('auth.deposit_amount_min', ['min' => number_format((int) config('financial.deposit_min', 10_000))]),
            'amount.max' => $maxMessage,
            'payment_method.in' => __('auth.payment_method_invalid'),
        ];
    }
}
