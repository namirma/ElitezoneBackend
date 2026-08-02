<?php

namespace Tests\Feature\Financial;

use App\Services\Financial\SettlementCalculatorService;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * چرا `Order::payment_of_owner` (POO) با `Order::final_amount` یکی نیست.
 *
 * فرانت در تستِ end-to-end صفحهٔ /order این اختلاف را گزارش کرد و پرسید باگ است
 * یا طراحی:
 *
 *     POO          = 344,456,640 ریال   ← آنچه از کیف پول خریدار کم می‌شود
 *     final_amount = 345,840,000 ریال   ← آنچه به مشتری نشان داده می‌شود
 *     اختلاف       =   1,383,360 ریال
 *
 * پاسخ: **باگِ محاسباتی نیست.** اختلاف دقیقاً ضریبِ `%OW` است — «تخفیفِ خریدار»،
 * سهمِ ۴۰٪ خریدار از کارمزدِ تراکنش (`%TC = ۱٪`)، یعنی ۰٫۴٪:
 *
 *     344,456,640 / 345,840,000 = 0.996 = 1 − 0.4/100
 *
 * فرمولِ POO در سند طراحی «پروسه خرید» ضریبِ `(1 − %OW)` دارد
 * (`SettlementCalculatorService::calculatePOO`)، ولی `final_amount` که
 * `PlaceOrderAction` می‌نویسد یک جمعِ حسابداریِ ساده است و این ضریب را ندارد:
 *
 *     final_amount = subtotal − discount + vat + shipping
 *
 * پس خریدار **کمتر** از عددِ نمایش‌داده‌شده می‌پردازد، نه بیشتر — یعنی خطر مالی
 * علیهِ مشتری نیست. ولی یک گپِ واقعیِ «نمایش در برابر واقعیت» است و تصمیم دربارهٔ
 * آن (نمایشِ POO به مشتری، یا اعمالِ %OW در `final_amount`) محصولی است نه فنی.
 *
 * این تست آن رابطه را **پین** می‌کند تا اگر روزی کسی یکی از دو طرف را عوض کرد،
 * سوییت سر و صدا کند به‌جای اینکه اختلاف بی‌صدا عوض شود.
 *
 * ⚠️ یک واگراییِ دومِ خفته هم هست که در آن تستِ فرانت دیده نشد چون کرایه صفر بود:
 * با `trans_vat = true`، فرمولِ POO مالیات را روی **کرایه هم** می‌بندد، در حالی که
 * `final_amount` مالیات را فقط روی کالا حساب می‌کند و کرایه را بدونِ مالیات اضافه
 * می‌کند. به‌محضِ اینکه `shipping_rates` واقعی وارد شود، اختلاف دیگر یک ضریبِ ساده
 * نخواهد بود. آخرین تستِ این فایل همان را نشان می‌دهد.
 */
class PooVersusDisplayedAmountTest extends TestCase
{
    /** %OW برای هر نشان ثابت است: ۴۰٪ از %TC، و %TC پیش‌فرض ۱٪ → ۰٫۴٪. */
    private const OW_PERCENT = 0.4;

    private const VAT_PERCENT = 10.0;

    #[Test]
    public function the_reported_gap_is_exactly_the_owner_discount_factor(): void
    {
        $calculator = app(SettlementCalculatorService::class);

        // اعدادِ گزارشِ فرانت، بازسازی‌شده: کالای ۳۱۴,۴۰۰,۰۰۰ ریالی بدون مالیات،
        // بدون کرایه، بدون تخفیف، مالیات ۱۰٪.
        $goodsExVat = 314_400_000.0;
        $trans = 0.0;

        // آنچه PlaceOrderAction در final_amount می‌نویسد: subtotal + vat + shipping.
        $finalAmount = $goodsExVat * (1 + self::VAT_PERCENT / 100);

        $poo = $calculator->calculatePOO(
            trans: $trans,
            op: $goodsExVat,      // OP × W = ارزش کالا
            weight: 1.0,
            owPercent: self::OW_PERCENT,
            vatPercent: self::VAT_PERCENT,
            transCommission: true,
            transVat: true,
        );

        $this->assertEqualsWithDelta(345_840_000.0, $finalAmount, 1.0,
            'عددِ نمایش‌داده‌شده در گزارش فرانت');
        $this->assertEqualsWithDelta(344_456_640.0, $poo, 1.0,
            'عددِ واقعاً کسرشده از کیف پول در گزارش فرانت');

        // و رابطهٔ دقیقِ بین آن دو — همین یک خط جوابِ سؤال است.
        $this->assertEqualsWithDelta(
            $finalAmount * (1 - self::OW_PERCENT / 100),
            $poo,
            1.0,
            'اختلاف باید دقیقاً ضریبِ (۱ − %OW) باشد، نه چیز دیگری'
        );
    }

    #[Test]
    public function the_buyer_is_never_charged_more_than_the_displayed_amount(): void
    {
        // مهم‌ترین خاصیتِ ایمنی: هر عددی که به مشتری نشان داده شود، مبلغِ کسرشده
        // نباید از آن بیشتر باشد. تا وقتی %OW نامنفی است این برقرار است.
        $calculator = app(SettlementCalculatorService::class);

        foreach ([1_000_000.0, 50_000_000.0, 314_400_000.0, 2_000_000_000.0] as $goodsExVat) {
            $finalAmount = $goodsExVat * (1 + self::VAT_PERCENT / 100);

            $poo = $calculator->calculatePOO(
                trans: 0.0,
                op: $goodsExVat,
                weight: 1.0,
                owPercent: self::OW_PERCENT,
                vatPercent: self::VAT_PERCENT,
            );

            $this->assertLessThanOrEqual($finalAmount + 1.0, $poo,
                "کسر از کیف پول نباید از مبلغِ نمایش‌داده‌شده بیشتر باشد (کالا: {$goodsExVat})");
        }
    }

    #[Test]
    public function with_real_freight_the_gap_is_no_longer_a_simple_factor(): void
    {
        // واگراییِ دوم: POO با `trans_vat = true` مالیات را روی کرایه هم می‌بندد،
        // ولی final_amount کرایه را بدونِ مالیات اضافه می‌کند. در تستِ فرانت دیده
        // نشد چون هیچ ردیفِ shipping_rates وجود نداشت و کرایه صفر بود.
        $calculator = app(SettlementCalculatorService::class);

        $goodsExVat = 314_400_000.0;
        $trans = 20_000_000.0;

        $finalAmount = $goodsExVat * (1 + self::VAT_PERCENT / 100) + $trans;

        $poo = $calculator->calculatePOO(
            trans: $trans,
            op: $goodsExVat,
            weight: 1.0,
            owPercent: self::OW_PERCENT,
            vatPercent: self::VAT_PERCENT,
            transCommission: true,
            transVat: true,
        );

        // دیگر ضریبِ ساده نیست — مالیاتِ روی کرایه اختلاف را وارونه می‌کند.
        $simpleFactorPrediction = $finalAmount * (1 - self::OW_PERCENT / 100);

        $this->assertGreaterThan(
            1.0,
            abs($poo - $simpleFactorPrediction),
            'با کرایهٔ غیرصفر، اختلافِ POO و final_amount دیگر فقط ضریبِ %OW نیست'
        );

        // و اینجا خریدار **بیشتر** از عددِ نمایش‌داده‌شده کسر می‌شود — همان حالتی
        // که واقعاً علیهِ مشتری است و باید پیش از ورودِ نرخ‌های واقعیِ حمل حل شود.
        $this->assertGreaterThan($finalAmount, $poo,
            'مالیاتِ روی کرایه باعث می‌شود POO از مبلغِ نمایش‌داده‌شده بیشتر شود');
    }
}
