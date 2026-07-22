<?php

namespace App\Http\Requests\Admin;

use App\Models\Coupon;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * ویرایش کد تخفیف — یکتایی code با نادیده‌گرفتن رکورد جاری.
 */
class UpdateCouponRequest extends FormRequest
{
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
        ];
    }

    /**
     * برای کوپنِ درصدی، مبلغ نباید از ۱۰۰ بیشتر باشد.
     */
    public function withValidator($validator): void
    {
        $validator->sometimes(Coupon::AMOUNT, 'max:100', function ($input) {
            // در PUTِ جزئی که فقط amount می‌آید (type ارسال نمی‌شود)، به typeِ ذخیره‌شدهٔ
            // کوپن fallback می‌کنیم؛ وگرنه گاردِ max:100 برای کوپنِ درصدی دور زده می‌شد.
            $type = $input->{Coupon::TYPE} ?? $this->route('coupon')?->{Coupon::TYPE};

            return $type === Coupon::TYPE_PERCENT;
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
