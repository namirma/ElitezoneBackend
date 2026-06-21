<?php

namespace App\Http\Requests\Auth;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;

class VerifyEmailRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array|string>
     */
    public function rules(): array
    {
        return [
            // `rfc` only (no `dns`): the dns rule does a synchronous DNS lookup on every
            // request — adds latency and rejects valid brand-new / MX-less domains. The
            // frontend contract asks for format validation + uniqueness, which this covers.
            'email' => 'required|email:rfc|unique:user_profiles,email,'.Auth::id().',user_id',
        ];
    }
}
