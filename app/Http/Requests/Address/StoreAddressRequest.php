<?php

namespace App\Http\Requests\Address;

use App\Models\Address;
use Illuminate\Foundation\Http\FormRequest;

class StoreAddressRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            Address::TITLE => ['nullable', 'string', 'max:100'],
            Address::PROVINCE_ID => ['required', 'integer', 'exists:province,id'],
            Address::CITY_ID => ['required', 'integer', 'exists:province_city,id'],
            Address::POSTAL_ADDRESS => ['required', 'string', 'max:1000'],
            Address::BUILDING_NUMBER => ['nullable', 'string', 'max:10'],
            Address::UNIT => ['nullable', 'string', 'max:10'],
            Address::POSTAL_CODE => ['nullable', 'string', 'regex:/^\d{10}$/'],
            Address::RECEIVER_NAME => ['required', 'string', 'max:100'],
            Address::RECEIVER_MOBILE => ['required', 'string', 'regex:/^(\+?98|0098|0)?9\d{9}$/'],
            Address::IS_DEFAULT => ['nullable', 'boolean'],
        ];
    }

    // 🚀 خواندن پیام‌ها از lang
    //
    // کلیدها با «_» جدا می‌شوند نه «.» — lang/fa/address.php صاف است
    // (`receiver_name_required`). قبلاً اینجا `address.receiver_name.required`
    // ساخته می‌شد که در فایل وجود نداشت، و چون __() کلیدِ پیدانشده را عیناً
    // برمی‌گرداند، کاربر به‌جای پیام فارسی رشته‌ی خامِ کلید را می‌دید.
    public function messages(): array
    {
        return [
            Address::POSTAL_ADDRESS.'.required' => __('address.postal_address_required'),
            Address::RECEIVER_NAME.'.required' => __('address.receiver_name_required'),
            Address::RECEIVER_MOBILE.'.required' => __('address.receiver_mobile_required'),
            Address::RECEIVER_MOBILE.'.regex' => __('address.receiver_mobile_regex'),
            Address::POSTAL_CODE.'.regex' => __('address.postal_code_regex'),
            Address::PROVINCE_ID.'.exists' => __('address.province_id_exists'),
            Address::CITY_ID.'.exists' => __('address.city_id_exists'),
        ];
    }
}
