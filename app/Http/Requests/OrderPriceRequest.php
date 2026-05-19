<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class OrderPriceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'variant_id' => 'required|exists:product_variants,id',
            'factory_id' => 'required|exists:factories,id',
            'location_id' => 'required|exists:factory_locations,id',
            'customer_type_id' => 'nullable|exists:customer_types,id',
            'badge_id' => 'nullable|exists:badges,id',
            'filter_categories' => 'nullable|array',
            'filter_categories.*' => 'exists:price_filter_categories,id',
            'quantities' => 'required|array',
            'quantities.*' => 'required|integer|min:1',
            'with_vat' => 'nullable|boolean',
        ];
    }
}
