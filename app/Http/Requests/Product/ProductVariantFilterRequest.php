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
            // گروهِ ویژگی («گرید»). تیک‌زدنِ یک گزینه‌ی مشخص با این ممکن نیست —
            // برای آن `filter_values` هست.
            'filter_categories' => 'nullable|array',
            'filter_categories.*' => 'integer|exists:attributes,id',
            // گزینه‌ی مشخص («A3») — چیزی که سایدبار واقعاً تیک می‌خورد.
            'filter_values' => 'nullable|array',
            'filter_values.*' => 'integer|exists:attribute_values,id',
            'sort' => 'nullable|in:newest,oldest,cheapest,most_expensive,best_selling',
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1|max:100',
        ];
    }
}
