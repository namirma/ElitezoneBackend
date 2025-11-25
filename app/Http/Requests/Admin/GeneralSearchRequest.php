<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class GeneralSearchRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'query'       => 'nullable|string|min:2',
            'type'        => 'nullable|string',
            'status'      => 'nullable|string',
            'search'      => 'nullable|string',
            'has_receipt' => 'nullable|boolean',
            'per_page'    => 'nullable|integer|max:100',
        ];
    }
}
