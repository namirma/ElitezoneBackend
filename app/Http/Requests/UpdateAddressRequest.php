<?php

namespace App\Http\Requests;

use App\Models\Address;
use Illuminate\Foundation\Http\FormRequest;

class UpdateAddressRequest extends FormRequest
{
    public function authorize(): bool
    {
        // اطمینان از اینکه کاربر مالک آدرس است
        $address = $this->route('address');
        return $address && $address->{Address::USER_ID} === $this->user()->id;
    }

    public function rules(): array
    {
        // قوانین آپدیت (همه اختیاری هستند)
        return [
            Address::TITLE => ['sometimes', 'required', 'string', 'max:100'],
            Address::PROVINCE_ID => ['sometimes', 'required', 'integer', 'exists:province,id'],
            Address::CITY_ID => ['sometimes', 'required', 'integer', 'exists:province_city,id'],
            Address::POSTAL_ADDRESS => ['sometimes', 'required', 'string', 'max:1000'],
            Address::BUILDING_NUMBER => ['nullable', 'string', 'max:10'],
            Address::UNIT => ['nullable', 'string', 'max:10'],
            Address::POSTAL_CODE => ['nullable', 'string', 'regex:/^\d{10}$/'],
            Address::RECEIVER_NAME => ['sometimes', 'required', 'string', 'max:100'],
            Address::RECEIVER_MOBILE => ['sometimes', 'required', 'string', 'regex:/^(\+?98|0098|0)?9\d{9}$/'],
        ];
    }

    public function messages(): array
    {
        return [
            Address::POSTAL_ADDRESS . '.required' => __('global.address.postal_address.required'),
            Address::RECEIVER_NAME . '.required' => __('global.address.receiver_name.required'),
            Address::RECEIVER_MOBILE . '.required' => __('global.address.receiver_mobile.required'),
        ];
    }
}
