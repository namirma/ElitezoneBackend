<?php

namespace App\Http\Requests\Product;

use Illuminate\Foundation\Http\FormRequest;

class ProductVariantFilterRequest extends FormRequest
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
     * @return array<string,
     */
    public function rules(): array
    {
        return [
            'filter_categories' => 'nullable|array',
            'filter_categories.*' => 'integer|exists:attributes,id',
            // 'filter_categories.*' => 'exists:price_filter_categories,id',
            'sort' => 'nullable|in:newest,oldest,cheapest,most_expensive,best_selling',
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1|max:100',
        ];
    }
}
