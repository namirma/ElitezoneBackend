<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\Concerns\ValidatesCouponFunders;
use App\Models\Coupon;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * ویرایش کد تخفیف — یکتایی code با نادیده‌گرفتن رکورد جاری.
 *
 * `funders[]` (اختیاری) مجموعه‌ی تأمین‌کننده‌ها را **جایگزین** می‌کند
 * (docs/specs/COUPON_FUNDING_2026-09-15.md §۳.۱).
 */
class UpdateCouponRequest extends FormRequest
{
    use ValidatesCouponFunders;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $coupon = $this->route('coupon');

        return [
            Coupon::CODE => [
                'sometimes', 'required', 'string', 'max:100',
                Rule::unique('coupons', 'code')->ignore($coupon?->id),
            ],
            Coupon::TYPE => ['sometimes', 'required', Rule::in([Coupon::TYPE_PERCENT, Coupon::TYPE_FIXED])],
            Coupon::AMOUNT => ['sometimes', 'required', 'numeric', 'min:0'],
            Coupon::USER_ID => ['nullable', 'integer', 'exists:users,id'],
            Coupon::EXPIRES_AT => ['nullable', 'date'],
            Coupon::REASON => ['nullable', 'string', 'max:500'],
            Coupon::IS_ACTIVE => ['nullable', 'boolean'],
            Coupon::BUDGET_TOTAL => ['nullable', 'integer', 'min:0'],
            Coupon::IS_MULTI_USE => ['sometimes', 'boolean'],
        ] + $this->funderRules(required: false);
    }

    /**
     * برای کوپنِ درصدی، مبلغ نباید از ۱۰۰ بیشتر باشد.
     */
    public function withValidator($validator): void
    {
        $coupon = $this->route('coupon');

        $validator->sometimes(Coupon::AMOUNT, 'max:100', function ($input) use ($coupon) {
            // در PUTِ جزئی که فقط amount می‌آید (type ارسال نمی‌شود)، به typeِ ذخیره‌شده‌ی
            // کوپن fallback می‌کنیم؛ وگرنه گاردِ max:100 برای کوپنِ درصدی دور زده می‌شد.
            $type = $input->{Coupon::TYPE}
                ?? ($coupon instanceof Coupon ? $coupon->{Coupon::TYPE} : null);

            return $type === Coupon::TYPE_PERCENT;
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
