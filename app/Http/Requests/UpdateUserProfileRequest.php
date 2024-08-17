<?php

namespace App\Http\Requests;

use App\Models\UserProfile;
use Illuminate\Foundation\Http\FormRequest;

class UpdateUserProfileRequest extends FormRequest
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
            UserProfile::NAME => 'string',
            UserProfile::FAMILY => 'string',
            UserProfile::EMAIL => 'email:rfc,dns',
            UserProfile::NATIONAL_CODE => 'digits:10',
            UserProfile::BIRTHDAY => 'string',
            UserProfile::COMPANY => 'string',
            UserProfile::ECONOMIC_CODE => 'required_with:company|string',
            UserProfile::NATIONAL_ID => 'required_with:company|string',
            UserProfile::REGISTER_ID => 'required_with:company|string',
            UserProfile::PHONE => 'required_with:company|string',
            UserProfile::PROVINCE_ID => 'numeric',
            UserProfile::CITY_ID => 'numeric',
            UserProfile::ENTITY => 'in:real,legal',
        ];
    }
}
