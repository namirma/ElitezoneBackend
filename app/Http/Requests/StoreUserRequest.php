<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'username' => $this->username,
        ]);
    }

    public function rules(): array
    {
        $isRegister = ! $this->has('code');

        return [
            'username'       => ['required', 'regex:/^(\+?98|0098|0)?9\d{9}$/'],
            'national_code'  => [$isRegister ? 'required' : 'nullable', 'regex:/^\d{10}$/'],
            'birth_date'     => [$isRegister ? 'required' : 'nullable', 'regex:/^(\d{8}|\d{4}-\d{2}-\d{2})$/'],
            'code'           => ['sometimes', 'digits:6', 'exists:users,secret'],
            'forget_password'=> ['nullable', 'boolean'],
            'roles'          => ['nullable', 'array'],
            'roles.*'        => ['in:engineer,contractor,employer,supplier'],
        ];
    }
}
