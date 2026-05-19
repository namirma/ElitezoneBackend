<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

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
            'username' => ['required', 'regex:/^(\+?98|0098|0)?9\d{9}$/'],
            'national_code' => [$isRegister ? 'required' : 'nullable', 'regex:/^\d{10}$/'],
            'birth_date' => [$isRegister ? 'required' : 'nullable', 'regex:/^(\d{8}|\d{4}-\d{2}-\d{2})$/'],
            'code' => ['sometimes', 'digits:6', 'exists:users,secret'],
            //'code'           => ['required', 'digits:6'],
            'forget_password' => ['nullable', 'boolean'],
            'roles' => ['nullable', 'array'],
            'roles.*' => ['in:employer,supplier,expert'],
        ];
    }

    public function messages(): array
    {
        return [
            'code.digits' => __('auth.code_digits'),
            'code.exists' => __('auth.code_exists'),
            'username.required' => __('auth.username_required'),
            'username.regex' => __('auth.username_regex'),
            'national_code.required' => __('auth.national_code_required'),
            'national_code.regex' => __('auth.national_code_regex'),
            'birth_date.required' => __('auth.birth_date_required'),
            'birth_date.regex' => __('auth.birth_date_regex'),
        ];
    }
}
