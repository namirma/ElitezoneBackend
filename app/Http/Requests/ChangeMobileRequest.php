<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ChangeMobileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'new_mobile' => ['required', 'regex:/^(\+?98|0098|0)?9\d{9}$/', Rule::unique('users', 'mobile')],
            'national_code' => ['required', 'digits:10'],
            'birth_date' => ['required', 'string'],
        ];
    }
}
