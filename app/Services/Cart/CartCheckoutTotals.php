<?php

declare(strict_types=1);

namespace App\Services\Cart;

use App\DataTransferObjects\Pricing\PricingContextData;
use App\Models\Cart;
use App\Models\PriceFilterCategory;
use App\Models\User;
use App\Services\Marketing\CouponService;
use App\Services\Pricing\CartPricingCalculator;
use App\Support\Money;

/**
 * «Final Amount» بند ۳.۳ — مبلغ سبد **پس از** اعمال کوپن.
 *
 * درخت تصمیم پرداخت روی همین عدد کار می‌کند، پس یک نقطه دارد و نه چند تا:
 * `payment-options`، `pay` و `reserve` هر سه از اینجا می‌خوانند. اگر هرکدام
 * خودش جمع می‌زد، کاربر می‌توانست شاخه‌ای ببیند که سرور در لحظه‌ی پرداخت
 * ردش می‌کند.
 *
 * کوپن روی سبد فقط **کد** است (بند ۳.۱۳)؛ تبدیلش به مبلغ همین‌جا و در لحظه‌ی
 * خواندن انجام می‌شود. کدِ نامعتبر/منقضی بی‌صدا نادیده گرفته می‌شود — این یک
 * مسیرِ نمایشی است و نباید صفحه‌ی پرداخت را با خطا بخواباند؛ اعتبارسنجیِ
 * سخت‌گیرانه در لحظه‌ی خودِ پرداخت انجام می‌شود.
 */
final class CartCheckoutTotals
{
    public function __construct(
        private readonly CartPricingCalculator $pricing,
        private readonly CartResolver $resolver,
        private readonly CouponService $coupons,
    ) {}

    public function finalAmountFor(Cart $cart, ?User $user): float
    {
        $total = $this->grossTotalFor($cart);

        return max(0.0, Money::toFloat(Money::sub($total, $this->couponDiscount($cart, $user, $total))));
    }

    /** جمعِ سبد پیش از کوپن. */
    public function grossTotalFor(Cart $cart): float
    {
        $activeCode = $this->resolver->activeCode();
        $filterId = PriceFilterCategory::where(PriceFilterCategory::CODE, $activeCode)->value('id');

        return (float) $this->pricing->calculate($cart, new PricingContextData(
            activeCode: $activeCode,
            customerType: $cart->customerType,
            badge: $cart->badge,
            filterCategoryIds: $filterId ? [$filterId] : $cart->priceFilterCategories->pluck('id')->all(),
            withVat: true,
            offerBasis: 'weight',
        ))->total;
    }

    /** تخفیفِ کوپنِ ذخیره‌شده روی سبد، یا صفر اگر کدی نباشد/معتبر نباشد. */
    public function couponDiscount(Cart $cart, ?User $user, float $payable): float
    {
        $code = $cart->{Cart::COUPON_CODE};

        if ($code === null || $code === '' || $user === null) {
            return 0.0;
        }

        try {
            return $this->coupons->calculateDiscount(
                $this->coupons->validateForCheckout($user, $code),
                $payable
            );
        } catch (\Throwable) {
            return 0.0;
        }
    }
}
