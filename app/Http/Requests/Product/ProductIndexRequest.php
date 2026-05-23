<?php

namespace App\Http\Requests\Product;

use App\DataTransferObjects\ProductFilterData;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProductIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $validSortOptions = [
            'cheapest',
            'most_expensive',
            'most_viewed',
            'best_selling',
            'newest',
        ];

        return [
            'category' => ['sometimes', 'nullable', 'integer', 'exists:product_categories,id'],
            'search' => ['sometimes', 'nullable', 'string', 'max:255'],
            'per_page' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:100'],
            'sort' => ['sometimes', 'nullable', 'string', Rule::in($validSortOptions)],
            'filter_categories' => ['sometimes', 'array'],
            'debug_prices' => ['sometimes', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (! $this->has('sort') || empty($this->input('sort'))) {
            $this->merge(['sort' => 'newest']);
        }
    }

    /**
     * تبدیل ریکوئست به DTO
     */
    public function toDto(string $activeRoleCode): ProductFilterData
    {
        return ProductFilterData::fromRequest($this, $activeRoleCode);
    }
}
