<?php

namespace App\Http\Requests\Order;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates the Moadian (taxpayer-system) invoice reference number.
 * Used for both Doc Q (eliteYar) and Doc O (supplier) submissions.
 */
class SubmitMoadianInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'moadian_ref' => ['required', 'string', 'min:3', 'max:100'],
        ];
    }

    public function messages(): array
    {
        return [
            'moadian_ref.required' => __('orders.errors.moadian_ref_required'),
            'moadian_ref.string'   => __('orders.errors.moadian_ref_required'),
            'moadian_ref.min'      => __('orders.errors.moadian_ref_required'),
            'moadian_ref.max'      => __('orders.errors.moadian_ref_required'),
        ];
    }
}
