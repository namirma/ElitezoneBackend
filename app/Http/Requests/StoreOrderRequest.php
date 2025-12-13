<?php

namespace App\Http\Requests;

use App\DataTransferObjects\PlaceOrderData;
use App\Models\Address;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class StoreOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'beneficiary_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'address_id' => [
                'nullable',
                'integer',
                Rule::exists('addresses', 'id')->where('user_id', Auth::id())
            ],
            'description' => ['nullable', 'string', 'max:1000'],
            'shipping_method' => ['nullable', 'string'],
        ];
    }

    public function toDto(): PlaceOrderData
    {
        return PlaceOrderData::fromRequest($this);
    }
}
