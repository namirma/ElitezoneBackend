<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreUserTypeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check();
    }

    public function rules(): array
    {
        return [
            'roles'                   => 'required_without:type_id|array|min:1',
            'roles.*'                 => 'in:engineer,contractor,employer,supplier',
            'employer_has_consultant' => 'nullable|boolean',
            'type_id'                 => 'nullable|integer|exists:user_types,id',
        ];
    }
}
