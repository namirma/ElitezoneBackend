<?php

namespace App\Http\Requests\Order;

use App\DataTransferObjects\Order\PlaceOrderData;
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
                'required',
                'integer',
                Rule::exists('addresses', 'id')->where('user_id', Auth::id()),
            ],
            'description' => ['nullable', 'string', 'max:1000'],
            'shipping_method_id' => ['required', 'integer', 'exists:shipping_methods,id'],
        ];
    }

    public function attributes(): array
    {
        return [
            'beneficiary_user_id' => 'کاربر ذینفع',
            'address_id' => 'آدرس گیرنده',
            'description' => 'توضیحات',
            'shipping_method_id' => 'روش ارسال',
        ];
    }

    public function messages(): array
    {
        return [
            'address_id.required' => __('orders.store_address_required'),
            'address_id.integer'  => __('orders.store_address_integer'),
            'address_id.exists'   => __('orders.store_address_exists'),

            'shipping_method_id.required' => __('orders.store_shipping_required'),
            'shipping_method_id.integer'  => __('orders.store_shipping_integer'),
            'shipping_method_id.exists'   => __('orders.store_shipping_exists'),

            'beneficiary_user_id.integer' => __('orders.store_beneficiary_integer'),
            'beneficiary_user_id.exists'  => __('orders.store_beneficiary_exists'),

            'description.max' => __('orders.store_description_max'),
        ];
    }

    public function toDto(): PlaceOrderData
    {
        return PlaceOrderData::fromRequest($this);
    }
}
