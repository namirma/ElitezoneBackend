<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AddToCartRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'variant_id'          => 'required|exists:product_variants,id',
            'factory_id'          => 'required|exists:factories,id',
            'location_id'         => 'required|exists:factory_locations,id',
            'shipping_method_id'  => 'required|exists:shipping_methods,id',
            'quantities'          => 'required|array',
            'quantities.*'        => 'required|integer|min:1',
            'filter_categories'   => 'nullable|array',
            'filter_categories.*' => 'exists:price_filter_categories,id',
        ];
    }
}
