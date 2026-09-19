<?php

namespace App\Http\Requests\Profile;

use App\Models\SupplierProfile;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * فرمِ «درخواستِ احرازِ صلاحیتِ تأمین‌کننده» — سایت فقط دروازه است
 * (`docs/specs/SUPPLIER_CONSOLIDATION_2026-09-19.md` §۴-۱).
 *
 * همان بدنه هم برای ارسالِ اولیه است هم برای ویرایش از پنل بعد از تأیید؛
 * تفاوت را کنترلر می‌فهمد (`isSubmittable()`). ۴۲۲ همیشه این‌جا می‌افتد، نه
 * در ستونِ کوتاهِ دیتابیس — به همین دلیل هر رشته `max` دارد.
 *
 * شخصِ حقوقی (`company`): شناسه‌ی ملی ۱۱ رقمی + شماره‌ی ثبت اجباری.
 * شخصِ حقیقی (`individual`): همین دو اختیاری، بقیه یکسان.
 *
 * @property mixed $activity_category_id
 */
class StoreSupplierProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array|string>
     */
    public function rules(): array
    {
        $isCompany = $this->input(SupplierProfile::ENTITY_TYPE, SupplierProfile::ENTITY_COMPANY) !== SupplierProfile::ENTITY_INDIVIDUAL;

        return [
            SupplierProfile::ENTITY_TYPE => ['sometimes', 'in:'.SupplierProfile::ENTITY_COMPANY.','.SupplierProfile::ENTITY_INDIVIDUAL],
            SupplierProfile::COMPANY => ['required', 'string', 'min:2', 'max:64'],
            SupplierProfile::NATIONAL_ID => [$isCompany ? 'required' : 'nullable', 'digits:11'],
            SupplierProfile::REGISTRATION_NUMBER => [$isCompany ? 'required' : 'nullable', 'string', 'max:20'],
            SupplierProfile::ECONOMIC_CODE => ['nullable', 'digits:12'],
            SupplierProfile::REPRESENTATIVE_NAME => ['required', 'string', 'min:2', 'max:100'],
            SupplierProfile::REPRESENTATIVE_NATIONAL_CODE => ['required', 'digits:10'],
            SupplierProfile::LANDLINE => ['nullable', 'regex:/^0\d{10}$/'],
            SupplierProfile::PROVINCE_ID => ['required', 'integer', 'exists:province,id'],
            SupplierProfile::CITY_ID => ['required', 'integer', 'exists:province_city,id'],
            SupplierProfile::FACTORY_ADDRESS => ['required', 'string', 'min:10', 'max:500'],
            SupplierProfile::POSTAL_CODE => ['required', 'digits:10'],
            SupplierProfile::IBAN => ['nullable', 'regex:/^IR\d{24}$/'],
            'activity_category_id' => ['required', 'array', 'min:1'],
            'activity_category_id.*' => ['exists:supplier_activity_categories,id'],

            SupplierProfile::ACTIVITY_DESCRIPTION => ['nullable', 'string', 'max:255'],
            SupplierProfile::BRANDS => ['nullable', 'string', 'max:255'],
            SupplierProfile::BRANDS_NOT_RELATED => ['boolean'],
            SupplierProfile::ACTIVITY_HISTORY => ['nullable', 'integer', 'min:0', 'max:99'],
            SupplierProfile::ACTIVITY_PROVINCES => ['nullable', 'string', 'max:128'],
            SupplierProfile::PRODUCTION_AMOUNT => ['nullable', 'string', 'max:255'],
            SupplierProfile::STANDARDS => ['nullable', 'string', 'max:255'],
            SupplierProfile::STANDARDS_NOT_RELATED => ['boolean'],
            SupplierProfile::WEBSITE => ['nullable', 'string', 'max:128'],
            SupplierProfile::WEBSITE_NOT_RELATED => ['boolean'],
        ];
    }

    /** ارقامِ فارسی/عربی را قبل از `digits:` لاتین می‌کند؛ شبا را بی‌فاصله و بزرگ. */
    protected function prepareForValidation(): void
    {
        $digitFields = [
            SupplierProfile::NATIONAL_ID, SupplierProfile::REGISTRATION_NUMBER, SupplierProfile::ECONOMIC_CODE,
            SupplierProfile::REPRESENTATIVE_NATIONAL_CODE, SupplierProfile::LANDLINE, SupplierProfile::POSTAL_CODE,
        ];
        $merge = [];
        foreach ($digitFields as $field) {
            $value = $this->input($field);
            if (is_string($value)) {
                $merge[$field] = self::latinDigits(trim($value));
            }
        }
        $iban = $this->input(SupplierProfile::IBAN);
        if (is_string($iban)) {
            $merge[SupplierProfile::IBAN] = strtoupper((string) preg_replace('/\s+/', '', self::latinDigits($iban)));
        }
        $this->merge($merge);
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            $code = (string) $this->input(SupplierProfile::REPRESENTATIVE_NATIONAL_CODE, '');
            if (preg_match('/^\d{10}$/', $code) && ! self::validNationalCode($code)) {
                $v->errors()->add(SupplierProfile::REPRESENTATIVE_NATIONAL_CODE, __('supplier.qualification.representative_national_code_invalid'));
            }
        });
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            SupplierProfile::COMPANY.'.required' => __('supplier.qualification.company_required'),
            SupplierProfile::NATIONAL_ID.'.required' => __('supplier.qualification.national_id_required'),
            SupplierProfile::NATIONAL_ID.'.digits' => __('supplier.qualification.national_id_digits'),
            SupplierProfile::REGISTRATION_NUMBER.'.required' => __('supplier.qualification.registration_number_required'),
            SupplierProfile::ECONOMIC_CODE.'.digits' => __('supplier.qualification.economic_code_digits'),
            SupplierProfile::REPRESENTATIVE_NAME.'.required' => __('supplier.qualification.representative_name_required'),
            SupplierProfile::REPRESENTATIVE_NATIONAL_CODE.'.required' => __('supplier.qualification.representative_national_code_required'),
            SupplierProfile::REPRESENTATIVE_NATIONAL_CODE.'.digits' => __('supplier.qualification.representative_national_code_invalid'),
            SupplierProfile::LANDLINE.'.regex' => __('supplier.qualification.landline_invalid'),
            SupplierProfile::PROVINCE_ID.'.required' => __('supplier.qualification.province_required'),
            SupplierProfile::PROVINCE_ID.'.exists' => __('supplier.qualification.province_required'),
            SupplierProfile::CITY_ID.'.required' => __('supplier.qualification.city_required'),
            SupplierProfile::CITY_ID.'.exists' => __('supplier.qualification.city_required'),
            SupplierProfile::FACTORY_ADDRESS.'.required' => __('supplier.qualification.factory_address_required'),
            SupplierProfile::FACTORY_ADDRESS.'.min' => __('supplier.qualification.factory_address_min'),
            SupplierProfile::POSTAL_CODE.'.required' => __('supplier.qualification.postal_code_required'),
            SupplierProfile::POSTAL_CODE.'.digits' => __('supplier.qualification.postal_code_digits'),
            SupplierProfile::IBAN.'.regex' => __('supplier.qualification.iban_invalid'),
            'activity_category_id.required' => __('supplier.qualification.categories_required'),
            'activity_category_id.min' => __('supplier.qualification.categories_required'),
        ];
    }

    private static function latinDigits(string $value): string
    {
        return strtr($value, [
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        ]);
    }

    /** الگوریتمِ رقمِ کنترلِ کدِ ملیِ ایران (ضرایب ۱۰..۲، باقی‌مانده بر ۱۱). */
    public static function validNationalCode(string $code): bool
    {
        if (! preg_match('/^\d{10}$/', $code) || preg_match('/^(\d)\1{9}$/', $code)) {
            return false;
        }
        $sum = 0;
        for ($i = 0; $i < 9; $i++) {
            $sum += (int) $code[$i] * (10 - $i);
        }
        $rest = $sum % 11;
        $check = (int) $code[9];

        return $rest < 2 ? $check === $rest : $check === 11 - $rest;
    }
}
