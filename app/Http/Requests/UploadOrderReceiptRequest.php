<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UploadOrderReceiptRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'receipt' => 'required|file|mimes:jpg,jpeg,png,pdf|max:5120', 
        ];
    }

    public function messages(): array
    {
        return [
            'receipt.required' => __('orders.receipt_required'),
            'receipt.mimes'    => __('orders.receipt_mimes'),
            'receipt.max'      => __('orders.receipt_max'),
        ];
    }
}
