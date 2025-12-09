<?php

namespace App\Http\Requests;

use App\Models\Address;
use App\Models\Order;
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
            Order::BENEFICIARY_USER_ID => 'nullable|exists:users,id',
            Order::ADDRESS_ID => [
                'nullable',
                Rule::exists('addresses', Address::ID)->where(Address::USER_ID, Auth::id())
            ],
        ];
    }
}
