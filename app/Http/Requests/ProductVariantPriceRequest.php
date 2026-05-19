<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

// مسیر دقیق هِلپرها را در پروژهٔ خود ست کنید

class ProductVariantPriceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'variant_id' => 'required|integer|exists:product_variants,id',
            'factory_id' => 'required|integer|exists:factories,id',
            'location_id' => 'required|integer|exists:factory_locations,id',
            'parameters' => 'nullable|array',
            'parameters.*' => 'integer|exists:purchase_parameter_values,id',
            'badge_id' => 'nullable|integer|exists:badges,id',
            'customer_type_id' => 'nullable|integer|exists:customer_types,id',
            'with_vat' => 'nullable|boolean',
        ];
    }

    private const FIELD_TO_MESSAGE = [
        'variant_id' => 'global.variant_not_found',
        'factory_id' => 'global.factory_not_found',
        'location_id' => 'global.location_not_found',
    ];

    protected function failedValidation(Validator $validator): void
    {
        $errors = $validator->errors()->toArray();
        $field = array_key_first($errors);
        $key = self::FIELD_TO_MESSAGE[$field] ?? 'global.notFound';

        throw new HttpResponseException(
            \error($key, [$field => [__($key)]], 404)
        );
    }
}
