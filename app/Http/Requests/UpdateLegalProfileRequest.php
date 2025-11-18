<?php

namespace App\Http\Requests;

use App\Models\UserProfile;
use Illuminate\Foundation\Http\FormRequest;

class UpdateLegalProfileRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            UserProfile::COMPANY => ['required', 'string', 'max:100'],
            UserProfile::ECONOMIC_CODE => ['required', 'string', 'max:50'],
            UserProfile::NATIONAL_ID => ['required', 'string', 'max:20'],
            UserProfile::REGISTER_ID => ['required', 'string', 'max:50'],
            UserProfile::PHONE => ['required', 'string', 'max:20'], 
        ];
    }
}
