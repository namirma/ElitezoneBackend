<?php

namespace App\Http\Requests\Marketing;

use App\Http\Requests\Concerns\ValidatesCouponFunders;
use App\Models\Coupon;
use App\Models\CouponFunder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * ساختِ کوپن توسط تأمین‌کننده (پنلش) یا الیت‌یار (پروفایلِ سایت) —
 * docs/specs/COUPON_FUNDING_2026-09-15.md §۳.۱.
 *
 * نوعِ سازنده از پیش‌فرضِ روت (`funder_type`) می‌آید، نه از بدنه — نقشِ
 * پاسخ‌دهنده نباید قابلِ جعل باشد (همان دلیلِ `ReserveExtensionController`).
 * ردیفِ همان نوع بدونِ کاربر به خودِ سازنده نسبت داده می‌شود و باید حضور داشته
 * باشد؛ همراهش فقط `company` و یک ردیف از نوعِ سوم مجاز است.
 */
class StoreFundedCouponRequest extends FormRequest
{
    use ValidatesCouponFunders;

    public function authorize(): bool
    {
        return in_array($this->actorType(), [CouponFunder::TYPE_SUPPLIER, CouponFunder::TYPE_ELITE_YAR], true);
    }

    public function rules(): array
    {
        return [
            Coupon::CODE => ['required', 'string', 'max:100', 'regex:/^[A-Za-z0-9\-_]+$/', 'unique:coupons,code'],
            Coupon::TYPE => ['required', Rule::in([Coupon::TYPE_PERCENT, Coupon::TYPE_FIXED])],
            Coupon::AMOUNT => ['required', 'numeric', 'min:1'],
            Coupon::USER_ID => ['nullable', 'integer', 'exists:users,id'],
            Coupon::EXPIRES_AT => ['nullable', 'date', 'after:now'],
            Coupon::REASON => ['nullable', 'string', 'max:500'],
        ] + $this->funderRules(required: true);
    }

    public function withValidator($validator): void
    {
        $validator->sometimes(Coupon::AMOUNT, 'max:100', fn ($input) => $input->{Coupon::TYPE} === Coupon::TYPE_PERCENT);

        $this->validateFunders($validator, $this->actorType(), (int) $this->user()->id);
    }

    public function messages(): array
    {
        return [
            'code.required' => __('admin.coupons.code_required'),
            'code.unique' => __('admin.coupons.code_unique'),
            'code.regex' => __('coupons.code_format'),
            'type.required' => __('admin.coupons.type_required'),
            'type.in' => __('admin.coupons.type_invalid'),
            'amount.required' => __('admin.coupons.amount_required'),
            'expires_at.after' => __('coupons.expires_in_past'),
        ] + $this->funderMessages();
    }

    public function actorType(): string
    {
        return (string) $this->route('funder_type');
    }

    /** فیلدهای خودِ کوپن — بدونِ `funders`. */
    public function couponAttributes(): array
    {
        return collect($this->validated())->except(['funders'])->all();
    }
}
