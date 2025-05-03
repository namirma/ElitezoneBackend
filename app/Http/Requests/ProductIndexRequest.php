<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ProductIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'category' => 'nullable|exists:product_categories,id',
            'search' => 'nullable|string|max:255',
            'sort' => 'nullable|in:most_viewed,newest,best_selling,cheapest,most_expensive',
            'per_page' => 'nullable|integer|min:1|max:100',
        ];
    }

}
