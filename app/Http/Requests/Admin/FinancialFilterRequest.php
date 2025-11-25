<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class FinancialFilterRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'type'           => 'nullable|string|in:initial,final,command,tax',
            'payment_status' => 'nullable|string',
            'mode'           => 'nullable|string|in:allocation,payment',
            'doc_type'       => 'nullable|string|in:waybill,tax',
            'per_page'       => 'nullable|integer|max:100',
        ];
    }
}
