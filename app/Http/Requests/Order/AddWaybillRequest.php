<?php

namespace App\Http\Requests\Order;

use App\Models\Order;
use Illuminate\Foundation\Http\FormRequest;

class AddWaybillRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            Order::WAYBILL_REF => 'required|string|max:255',
        ];
    }

    public function messages(): array
    {
        return [
            Order::WAYBILL_REF.'.required' => __('orders.waybill_required'),
        ];
    }
}
