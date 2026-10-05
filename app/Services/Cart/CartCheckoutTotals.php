<?php

declare(strict_types=1);

namespace App\Services\Cart;

use App\DataTransferObjects\Pricing\PricingContextData;
use App\Exceptions\BusinessException;
use App\Models\Cart;
use App\Models\Coupon;
use App\Models\PriceFilterCategory;
use App\Models\User;
use App\Services\Financial\SettlementCalculatorService;
use App\Services\Marketing\CouponFundingService;
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
        private readonly CouponFundingService $funding,
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
     * ‏`shipping_included` دو حالتِ ناقص را پوشش می‌دهد و نه بیشتر:
     * مقصدی پیدا نشد، **یا** مقصد پیدا شد ولی هیچ نرخی برای آن مسیر وارد نشده.
     * تیم فرانت درست گفت که این دومی قبلاً `true` با کرایه‌ی صفر گزارش می‌شد و از
     * کرایه‌ی واقعاً رایگان جدا نبود؛ حالا `ShipmentGroup::$ratePriced` تفکیکش
     * می‌کند. کرایه‌ی صفرِ **قیمت‌گذاری‌شده** همچنان `true` است.
     *
     * @return array{final_amount: float, gross: float, shipping: float, shipping_included: bool, discount: float, coupon_error: ?string}
     */
    public function breakdownFor(Cart $cart, ?User $user, ?int $addressId = null): array
    {
        // ریالِ صحیح در مرز، نه فقط روی جمع: `gross` از قیمت‌گذاریِ ۴رقمی و
        // `shipping` از نرخِ ۲رقمی می‌آیند. تا پیش از این فقط `final_amount` گرد
        // می‌شد، پس `goods + shipping − coupon` روی سیم با خودِ `final_amount`
        // نمی‌خواند و هر مصرف‌کننده‌ای که اجزا را جمع می‌زد عددِ دیگری می‌گرفت.
        $gross = round($this->grossTotalFor($cart));

        [$shipping, $included] = $this->shippingFor($cart, $user, $addressId);
        $shipping = round($shipping);

        $withShipping = Money::add((string) $gross, (string) $shipping);
        ['discount' => $discount, 'error' => $couponError] = $this->couponFor($cart, $user, Money::toFloat($withShipping));

        return [
            'gross' => $gross,
            'shipping' => $shipping,
            'shipping_included' => $included,
            'discount' => $discount,
            // چرا کوپنِ روی سبد صفر شمرده شد — تا فرانت به‌جای «۰ ریال» دلیل را بگوید.
            'coupon_error' => $couponError,
            // اجزا و تخفیف همه صحیح‌اند؛ گردکردنِ جمع فقط برای اطمینان می‌ماند.
            'final_amount' => max(0.0, round(Money::toFloat(Money::sub($withShipping, (string) $discount)))),
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

        // «مقصد پیدا شد» کافی نیست: `ShippingCostCalculator` وقتی هیچ ردیفِ نرخی
        // برای مسیر نباشد بدونِ خطا صفر برمی‌گرداند (تنزلِ آرام، تا وقتی نرخ‌ها
        // وارد شوند). پس صفرِ «نرخ وارد نشده» از کرایه‌ی واقعاً رایگان قابلِ
        // تشخیص نبود. حالا هر محموله می‌گوید آیا نرخی قیمتش گذاشته یا نه.
        $priced = $quote->shipments !== []
            && collect($quote->shipments)->every(fn ($g) => $g->ratePriced);

        return [Money::toFloat(Money::add((string) $quote->total, (string) $vat)), $priced];
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
        return $this->couponFor($cart, $user, $payable)['discount'];
    }

    /**
     * تخفیف **و دلیلِ صفر بودنش**.
     *
     * پیش‌نمایش همان دو گاردِ ثبتِ سفارش را می‌گذراند: اعتبارِ خودِ کد
     * (`validateForCheckout`) و هویتِ تأمین‌کننده‌ها (`assertFundersMatch` —
     * همان که `PlaceOrderAction::assertCouponFundable` از راهِ `plan()` می‌زند).
     * کوپنِ الیت‌یاری روی سبدِ بی‌الیت‌یار دیگر «۰ ریال» نمی‌شود؛ صفر با پیام.
     *
     * الیت‌یارِ سفارش از خودِ سبد خوانده می‌شود (`commissionedEliteYARFor`) —
     * همان قاعده‌ای که ثبت دارد، پس پیش‌نمایش و ثبت یک تصمیم می‌گیرند.
     *
     * @return array{discount: float, error: ?string}
     */
    public function couponFor(Cart $cart, ?User $user, float $payable): array
    {
        $code = $cart->{Cart::COUPON_CODE};

        if ($code === null || $code === '' || $user === null) {
            return ['discount' => 0.0, 'error' => null];
        }

        try {
            $coupon = $this->coupons->validateForCheckout($user, $code);
            $this->assertCouponFundableOn($coupon, $cart);

            return ['discount' => $this->coupons->calculateDiscount($coupon, $payable), 'error' => null];
        } catch (BusinessException $e) {
            return ['discount' => 0.0, 'error' => $e->getMessage()];
        } catch (\Throwable) {
            return ['discount' => 0.0, 'error' => null];
        }
    }

    /**
     * هویتِ تأمین‌کننده‌های کوپن با این سبد می‌خواند؟ — همان گاردی که `couponFor()`
     * برای پیش‌نمایش و `PlaceOrderAction` از راهِ `plan()` برای ثبت می‌زند.
     *
     * عمومی است تا `POST checkout/coupon-preview` هم همین را بپرسد: کدِ «معتبر»ی
     * که سبدِ فعلی نمی‌تواند تأمینش کند باید همان لحظه و با همان پیام رد شود،
     * نه بعد از نشستن روی سبد (QA 2026-09-15، یافته‌ی ۱). یک قاعده، یک جا.
     *
     * @throws BusinessException
     */
    public function assertCouponFundableOn(Coupon $coupon, Cart $cart): void
    {
        $supplierId = $cart->{Cart::SUPPLIER_USER_ID}
            ?? $cart->items->first()?->factory?->supplier_user_id;

        $this->funding->assertFundersMatch(
            $coupon,
            $supplierId !== null ? (int) $supplierId : null,
            $this->commissionedEliteYARFor($cart),
        );
    }

    /**
     * الیت‌یارِ کمیسیون‌گیرِ این سبد، به همان قاعده‌ی `PlaceOrderAction`:
     * الیت‌یارِ مؤثر = صاحبِ سبد **اگر** `isEliteYAR()` باشد (بدنه‌ی `pay-for` هیچ
     * `elite_yar_user_id`ای ندارد)، و کمیسیون‌گیر یعنی «ست‌شده و غیر از پرداخت‌کننده».
     * در خریدِ مستقیم پرداخت‌کننده خودِ صاحبِ سبد است، پس هیچ‌کس — کوپنِ الیت‌یاری
     * روی آن رد می‌شود، همان‌طور که ثبت ردش می‌کند. در سبدِ لینک‌شده برای کارفرما
     * (`payForBeneficiary`) کارفرما می‌پردازد، پس صاحبِ سبد — به شرطِ همان نقشی
     * که ثبت می‌سنجد؛ پیش از این پیش‌نمایشِ کارفرما (`pay-for/{cart}`) کوپنِ همان
     * الیت‌یار را صفر می‌شمرد در حالی که ثبت آن را کسر می‌کرد — کارفرما ناچار بود
     * بیش از مبلغِ واقعی شارژ کند.
     */
    private function commissionedEliteYARFor(Cart $cart): ?int
    {
        if (! $cart->isForBeneficiary()) {
            return null;
        }

        $cart->loadMissing('user');

        // تأییدشده هم باید باشد — همان قیدی که `PlaceOrderAction::resolveEffectiveEliteYARId`
        // می‌گذارد (الیت‌یارِ هولد سهمی ندارد — پرسشِ ۱ مدیریت، ۱۴۰۵/۰۷/۱۳).
        $owner = $cart->user;

        return ($owner?->isEliteYAR() && $owner->isVerifiedEliteYAR()) ? (int) $cart->{Cart::USER_ID} : null;
    }
}
