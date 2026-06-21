<?php

namespace App\Http\Requests\Profile;

use Illuminate\Foundation\Http\FormRequest;

class StoreAvatarRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'avatar' => ['required', 'file', 'mimes:jpeg,jpg,png,webp', 'max:5120'], // 5 MB
        ];
    }

    public function messages(): array
    {
        return [
            'avatar.required' => __('profile.avatar_required'),
            'avatar.mimes' => __('profile.avatar_mimes'),
            'avatar.max' => __('profile.avatar_max'),
        ];
    }
}
