<?php

namespace App\Http\Requests\Cart;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class AddToCartRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'product_variant_id' => ['required', 'integer', 'exists:product_variants,id'],
            'factory_id' => ['required', 'integer', 'exists:factories,id'],
            'location_id' => [
                'required',
                'integer',
                'exists:factory_locations,id',
                Rule::exists('factory_locations', 'id')->where('factory_id', $this->factory_id),
            ],
            'shipping_method_id' => ['required', 'integer', 'exists:shipping_methods,id'],
            // Optional per-line delivery destination (multi-destination carts). Must belong
            // to the authenticated user; guests have none, so it stays null for them.
            'address_id' => ['nullable', 'integer', Rule::exists('addresses', 'id')->where('user_id', Auth::id())],
            'quantities' => ['required', 'array'],
            'quantities.*' => ['required', 'integer', 'min:1'],
            'filter_categories' => ['nullable', 'array'],
            'filter_categories.*' => ['integer', 'exists:price_filter_categories,id'],
        ];
    }
}
