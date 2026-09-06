<?php

declare(strict_types=1);

namespace App\Http\Requests\Supplier;

use Illuminate\Foundation\Http\FormRequest;

/**
 * آپلودِ گروهیِ موجودی — قرینه‌ی `BulkPriceRequest`؛ همان دلیلِ آرایه‌ی JSON.
 */
class BulkStockRequest extends FormRequest
{
    public const MAX_ROWS = 500;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'rows' => ['required', 'array', 'min:1', 'max:'.self::MAX_ROWS],
            'rows.*.product_variant_id' => ['required', 'integer'],
            'rows.*.factory_id' => ['required', 'integer'],
            'rows.*.factory_location_id' => ['required', 'integer'],
            'rows.*.quantity' => ['required', 'numeric', 'min:0'],
        ];
    }

    public function messages(): array
    {
        return [
            'rows.required' => __('supplier.bulk.rows_required'),
            'rows.max' => __('supplier.bulk.too_many_rows', ['max' => self::MAX_ROWS]),
        ];
    }
}
