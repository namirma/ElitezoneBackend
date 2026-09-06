<?php

declare(strict_types=1);

namespace App\Services\Cart;

use App\DataTransferObjects\Pricing\PricingContextData;
use App\Models\Cart;
use App\Models\PriceFilterCategory;
use App\Models\User;
use App\Services\Financial\SettlementCalculatorService;
use App\Services\Marketing\CouponService;
use App\Services\Pricing\CartPricingCalculator;
use App\Services\Shipping\CartShippingQuoteService;
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
        private readonly CartShippingQuoteService $shippingQuotes,
        private readonly SettlementCalculatorService $settlement,
    ) {}

    public function finalAmountFor(Cart $cart, ?User $user, ?int $addressId = null): float
    {
        return $this->breakdownFor($cart, $user, $addressId)['final_amount'];
    }

    /**
     * پایه‌ی درختِ تصمیم، با کرایه — و صریح درباره‌ی اینکه کرایه شمرده شده یا نه.
     *
     * ## باگ
     *
     * ‏`CartPricingCalculator` کرایه را `'0.0000'` هاردکد می‌کند، پس این پایه
     * **بدونِ کرایه و مالیاتِ کرایه** بود؛ ولی `PlaceOrderAction` می‌نویسد
     * `final_amount = subtotal − discount + totalVat + shippingCost` که هر دو
     * را دارد. یعنی عددی که شاخه با آن تصمیم می‌گرفت با عددی که از کیف پول کم
     * می‌شد یکی نبود.
     *
     * دو پیامد، و دومی بدتر است: `gateway_amount` به‌اندازه‌ی کرایه کم بود (پس
     * کاربری که دقیقاً همان را شارژ می‌کرد باز هم کسری داشت)، و **خودِ شاخه
     * می‌توانست غلط باشد** — کسی که موجودی‌اش کالا را می‌پوشاند ولی کالا+کرایه
     * را نه، شاخه‌ی `wallet` می‌گرفت و دکمه‌ای می‌دید که قطعاً ۴۲۲ می‌گیرد.
     * دقیقاً همان چیزی که بند ۶.۱ («منبع واحد») برای جلوگیری از آن نوشته شده.
     *
     * ## مقصد
     *
     * کرایه بدونِ مقصد معنا ندارد، و `payment-options` آدرس نمی‌گرفت. ترتیبِ
     * حل: آدرسِ صریحِ درخواست ← `carts.address_id` ← آدرسِ پیش‌فرضِ کاربر.
     *
     * وقتی هیچ‌کدام نباشد، کرایه صفر می‌ماند ولی `shipping_included` صریحاً
     * `false` است — پایه‌ی ناقص که **می‌گوید** ناقص است، به‌جای عددی که وانمود
     * می‌کند کامل است.
     *
     * @return array{final_amount: float, gross: float, shipping: float, shipping_included: bool, discount: float}
     */
    public function breakdownFor(Cart $cart, ?User $user, ?int $addressId = null): array
    {
        $gross = $this->grossTotalFor($cart);

        [$shipping, $included] = $this->shippingFor($cart, $user, $addressId);

        $withShipping = Money::add((string) $gross, (string) $shipping);
        $discount = $this->couponDiscount($cart, $user, Money::toFloat($withShipping));

        return [
            'gross' => $gross,
            'shipping' => $shipping,
            'shipping_included' => $included,
            'discount' => $discount,
            'final_amount' => max(0.0, Money::toFloat(Money::sub($withShipping, (string) $discount))),
        ];
    }

    /**
     * کرایه + مالیاتِ کرایه، به همان شکلی که `PlaceOrderAction` حسابش می‌کند.
     *
     * @return array{0: float, 1: bool} [مبلغ، آیا مقصد پیدا شد]
     */
    private function shippingFor(Cart $cart, ?User $user, ?int $addressId): array
    {
        $destination = $addressId
            ?? $cart->{Cart::ADDRESS_ID}
            ?? ($user?->addresses()->value('id'));

        if ($destination === null) {
            return [0.0, false];
        }

        try {
            $quote = $this->shippingQuotes->quoteForCart($cart, (int) $destination);
        } catch (\Throwable) {
            // نرخِ حملی برای این مسیر وارد نشده، یا مقصد به کاربر تعلق ندارد.
            // صفر با پرچمِ `false` — نه صفرِ خاموش.
            return [0.0, false];
        }

        // مالیاتِ کرایه هم بخشی از `final_amount` است (بند ±VAT قراردادِ
        // تأمین‌کننده)، پس اگر اینجا نیاید پایه باز هم از سفارش کمتر می‌ماند.
        $vat = $this->settlement->vatOnTrans(
            $quote->total,
            $this->settlement->currentVatRate(),
            $this->settlement->transVatFlagForSupplier(
                $cart->items->first()?->factory?->supplier_user_id
            )
        );

        return [Money::toFloat(Money::add((string) $quote->total, (string) $vat)), true];
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
