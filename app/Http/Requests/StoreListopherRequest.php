<?php

namespace App\Http\Requests;

use App\Models\Listopher;
use Illuminate\Foundation\Http\FormRequest;

class StoreListopherRequest extends FormRequest
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
            Listopher::PROJECT_CODE => 'required|string',
            Listopher::TYPE => 'required|in:stirrups,rebars',
            'items' => 'nullable|array',
            'items.*.pos' => 'nullable|integer',
            'items.*.shape' => 'nullable|integer',
            'items.*.length' => 'nullable|numeric',
            'items.*.size' => 'nullable|integer',
            'items.*.hook' => 'nullable|numeric',
            'items.*.overlap' => 'nullable|numeric',
            'items.*.quantity' => 'nullable|integer',
            'items.*.lengthTotal' => 'nullable|numeric',
            'items.*.b' => 'nullable|numeric',
            'items.*.h' => 'nullable|numeric',
        ];
    }
}
