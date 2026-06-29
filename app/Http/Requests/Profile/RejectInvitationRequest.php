<?php

namespace App\Http\Requests\Profile;

use Illuminate\Foundation\Http\FormRequest;

class RejectInvitationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'reject_reason' => 'required|string|max:255',
        ];
    }

    public function messages(): array
    {
        return [
            'reject_reason.required' => '.لطفاً دلیل عدم همکاری را مشخص کنید',
        ];
    }
}
