<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\Concerns\ValidatesCouponFunders;
use App\Models\Coupon;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * ساخت کد تخفیف جدید توسط ادمین.
 *
 * `funders[]` اختیاری است (docs/specs/COUPON_FUNDING_2026-09-15.md §۳.۱): نبودش
 * یعنی «شرکت ۱۰۰٪» — همان رفتارِ پیش از این قابلیت، این‌بار صریح.
 */
class StoreCouponRequest extends FormRequest
{
    use ValidatesCouponFunders;

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
            // بودجه‌ی کمپین (ریال): پر = چندمصرفه تا پایانِ بودجه؛ خالی = تک‌مصرفه (پرسشِ ۱۰ مدیریت).
            Coupon::BUDGET_TOTAL => ['nullable', 'integer', 'min:0'],
        ] + $this->funderRules(required: false);
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

        $this->validateFunders($validator);
    }

    public function messages(): array
    {
        return [
            'code.required' => __('admin.coupons.code_required'),
            'code.unique' => __('admin.coupons.code_unique'),
            'type.required' => __('admin.coupons.type_required'),
            'type.in' => __('admin.coupons.type_invalid'),
            'amount.required' => __('admin.coupons.amount_required'),
        ] + $this->funderMessages();
    }

    /** فیلدهای خودِ کوپن — بدونِ `funders` که جدولِ دیگری دارد. */
    public function couponAttributes(): array
    {
        return collect($this->validated())->except(['funders'])->all();
    }
}
