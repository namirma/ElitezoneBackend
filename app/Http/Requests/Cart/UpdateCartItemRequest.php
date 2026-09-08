<?php

namespace App\Http\Requests\Cart;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

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
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'quantities' => 'required|array',
            'quantities.*' => 'required|integer|min:1',
            'location_id' => 'required|exists:factory_locations,id',
            // Optional per-line delivery destination (multi-destination carts).
            'address_id' => ['nullable', 'integer', Rule::exists('addresses', 'id')->where('user_id', Auth::id())],
            'filter_categories' => 'nullable|array',
            'filter_categories.*' => 'integer|exists:price_filter_categories,id',
        ];
    }
}
