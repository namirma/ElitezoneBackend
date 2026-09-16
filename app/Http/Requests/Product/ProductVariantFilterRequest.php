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
            // `most_viewed` → `product_variants.view_count` (نوشته‌ی `LogProductView` از
            // `POST /products/variants/{variant}/view`)؛ `best_selling` → جمعِ
            // `order_items.quantity`.
            'sort' => 'nullable|in:newest,oldest,cheapest,most_expensive,most_viewed,best_selling',
            // موجودی از روی جمعِ نمایشیِ `stock_quantity` (رول‌آپِ factory_stocks).
            'availability' => 'nullable|in:all,in_stock,out_of_stock',
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1|max:100',
        ];
    }
}
