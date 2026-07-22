<?php

namespace App\Http\Requests\Admin;

use App\Models\Coupon;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * ساخت کد تخفیف جدید توسط ادمین.
 */
class StoreCouponRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            Coupon::CODE => ['required', 'string', 'max:100', 'unique:coupons,code'],
            Coupon::TYPE => ['required', Rule::in([Coupon::TYPE_PERCENT, Coupon::TYPE_FIXED])],
            Coupon::AMOUNT => ['required', 'numeric', 'min:0'],
            Coupon::USER_ID => ['nullable', 'integer', 'exists:users,id'],
            Coupon::EXPIRES_AT => ['nullable', 'date'],
            Coupon::REASON => ['nullable', 'string', 'max:500'],
            Coupon::IS_ACTIVE => ['nullable', 'boolean'],
        ];
    }

    /**
     * برای کوپنِ درصدی، مبلغ نباید از ۱۰۰ بیشتر باشد (کوپنِ «۵۰۰٪» داده‌ی بی‌معناست).
     * هرچند CouponService در محاسبه کلمپ می‌کند، ورودِ داده‌ی نامعتبر را همین‌جا می‌بندیم.
     */
    public function withValidator($validator): void
    {
        $validator->sometimes(Coupon::AMOUNT, 'max:100', function ($input) {
            return $input->{Coupon::TYPE} === Coupon::TYPE_PERCENT;
        });
    }

    public function messages(): array
    {
        return [
            'code.required' => __('admin.coupons.code_required'),
            'code.unique' => __('admin.coupons.code_unique'),
            'type.required' => __('admin.coupons.type_required'),
            'type.in' => __('admin.coupons.type_invalid'),
            'amount.required' => __('admin.coupons.amount_required'),
        ];
    }
}
