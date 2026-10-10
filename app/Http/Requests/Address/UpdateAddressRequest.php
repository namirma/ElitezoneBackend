<?php

namespace App\Http\Requests\Address;

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
        // پلاک و کد پستی اجباری‌اند (علی ۱۴۰۵/۰۷/۱۷). ویرایشِ جزئی مجاز است، ولی آدرسی
        // که هنوز آن‌ها را ندارد (قدیمی) با هیچ ویرایشی بدونِ آن‌ها ذخیره نمی‌شود.
        $mandatory = fn (string $field): array => blank($this->route('address')?->{$field})
            ? ['required']
            : ['sometimes', 'required'];

        return [
            Address::TITLE => ['sometimes', 'required', 'string', 'max:100'],
            Address::PROVINCE_ID => ['sometimes', 'required', 'integer', 'exists:province,id'],
            Address::CITY_ID => ['sometimes', 'required', 'integer', 'exists:province_city,id'],
            Address::POSTAL_ADDRESS => ['sometimes', 'required', 'string', 'max:1000'],
            Address::BUILDING_NUMBER => [...$mandatory(Address::BUILDING_NUMBER), 'string', 'max:10'],
            Address::UNIT => ['nullable', 'string', 'max:10'],
            Address::POSTAL_CODE => [...$mandatory(Address::POSTAL_CODE), 'string', 'regex:/^\d{10}$/'],
            Address::RECEIVER_NAME => ['sometimes', 'required', 'string', 'max:100'],
            Address::RECEIVER_MOBILE => ['sometimes', 'required', 'string', 'regex:/^(\+?98|0098|0)?9\d{9}$/'],
            Address::IS_DEFAULT => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            Address::POSTAL_ADDRESS.'.required' => __('global.address.postal_address.required'),
            Address::RECEIVER_NAME.'.required' => __('global.address.receiver_name.required'),
            Address::RECEIVER_MOBILE.'.required' => __('global.address.receiver_mobile.required'),
            Address::BUILDING_NUMBER.'.required' => __('address.building_number_required'),
            Address::POSTAL_CODE.'.required' => __('address.postal_code_required'),
            Address::POSTAL_CODE.'.regex' => __('address.postal_code_regex'),
        ];
    }
}
