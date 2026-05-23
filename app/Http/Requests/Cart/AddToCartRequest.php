<?php

namespace App\Http\Requests\Cart;

use Illuminate\Foundation\Http\FormRequest;
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
            'quantities' => ['required', 'array'],
            'quantities.*' => ['required', 'integer', 'min:1'],
            'filter_categories' => ['nullable', 'array'],
            'filter_categories.*' => ['integer', 'exists:price_filter_categories,id'],
        ];
    }
}
