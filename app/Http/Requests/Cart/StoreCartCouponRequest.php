<?php

namespace App\Http\Requests\Cart;

use Illuminate\Foundation\Http\FormRequest;

/**
 * ذخیره‌ی **کدِ** کوپن روی سبد — بند ۳.۱ و ۳.۱۳.
 *
 * عمداً هیچ اعتبارسنجیِ وجود/انقضا/سقفِ کوپن اینجا نیست: در این مرحله فقط کد
 * نگه داشته می‌شود و تبدیلش به مبلغ در لحظه‌ی رزرو یا پرداخت انجام می‌گیرد
 * (`CouponService::validateForCheckout`). دلیلش این است که یک کوپن می‌تواند
 * میان افزودن به سبد و پرداخت منقضی یا مصرف شود؛ اعتبارسنجیِ زودهنگام یک
 * تضمینِ دروغین به کاربر می‌داد.
 *
 * `null` مجاز است و یعنی «کوپن را بردار».
 */
class StoreCartCouponRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'coupon_code' => ['present', 'nullable', 'string', 'max:100'],
        ];
    }

    public function messages(): array
    {
        return [
            'coupon_code.present' => __('cart.coupon.code_required'),
            'coupon_code.string' => __('cart.coupon.code_invalid'),
            'coupon_code.max' => __('cart.coupon.code_invalid'),
        ];
    }
}
