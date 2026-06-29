<?php

namespace App\Http\Requests\Order;

use App\Models\Order;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;

class PayHoldFeeRequest extends FormRequest
{
    public function authorize(): bool
    {
        $order = $this->route('order');

        return $order && $order->{Order::PLACER_USER_ID} === Auth::id();
    }

    public function rules(): array
    {
        return [];
    }
}
