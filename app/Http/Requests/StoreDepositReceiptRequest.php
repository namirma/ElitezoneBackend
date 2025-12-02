<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreDepositReceiptRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'amount'        => 'required|integer|min:10000',
            'receipt_number'=> 'required|string|max:50',
            'payment_date'  => 'required|string',
            'bank_name'     => 'required|string|max:100',
            'description'   => 'nullable|string|max:500',
            'receipt_image' => 'required|file|mimes:jpg,jpeg,png,pdf|max:5120',
        ];
    }

}
