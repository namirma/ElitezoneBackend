<?php

namespace App\Http\Requests\Order;

use App\DataTransferObjects\PlaceOrderData;
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
            'address_id.required' => 'انتخاب آدرس گیرنده برای ثبت سفارش الزامی است.',
            'address_id.integer' => 'فرمت آدرس گیرنده نامعتبر است.',
            'address_id.exists' => 'آدرس انتخاب شده در سیستم یافت نشد یا متعلق به شما نیست.',

            'shipping_method_id.required' => 'انتخاب روش ارسال برای ثبت سفارش الزامی است.',
            'shipping_method_id.integer' => 'فرمت روش ارسال نامعتبر است.',
            'shipping_method_id.exists' => 'روش ارسال انتخاب شده در سیستم معتبر نیست.',

            'beneficiary_user_id.integer' => 'فرمت کاربر ذینفع نامعتبر است.',
            'beneficiary_user_id.exists' => 'کاربر ذینفع در سیستم یافت نشد.',

            'description.max' => 'توضیحات سفارش نمی‌تواند بیشتر از 1000 کاراکتر باشد.',
        ];
    }

    public function toDto(): PlaceOrderData
    {
        return PlaceOrderData::fromRequest($this);
    }
}
