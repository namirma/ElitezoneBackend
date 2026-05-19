<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateCartItemRequest extends FormRequest
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
            'quantities' => 'required|array',
            'quantities.*' => 'required|integer|min:1',
            'location_id' => 'required|exists:factory_locations,id',
            'filter_categories' => 'nullable|array',
            'filter_categories.*' => 'integer|exists:price_filter_categories,id',
        ];
    }
}
