<?php

namespace App\Http\Requests;

use App\Models\UserProfile;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;

class VerifyEmailRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $user = Auth::user();

        $profile = $user->profile;

        dd($profile);
        return is_null($profile) || (is_null($profile->email_verified_at ?? null));
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array|string>
     */
    public function rules(): array
    {
        return [
            UserProfile::EMAIL => 'required|email:rfc,dns',
        ];
    }

}
