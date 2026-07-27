<?php

declare(strict_types=1);

namespace App\Services\Financial;

use App\Models\Order;
use App\Models\PriceSetting;
use App\Support\Money;

/**
 * Financial settlement calculator.
 *
 * Implements all four POO / PTS formula variants defined in the purchase-process
 * design document ("پروسه خرید"), selected by two per-supplier contract flags:
 *
 *   +%TC (transCommission=true)  — commission is deducted from Trans as well as
 *                                   the product price
 *   -%TC (transCommission=false) — Trans is excluded from commission deduction
 *   +VAT (transVat=true)         — VAT is applied to Trans
 *   -VAT (transVat=false)        — Trans is excluded from VAT
 *
 * The current default %TC rate is 1 % of the invoice subtotal (ex-VAT).
 * This is a per-supplier contract parameter; a super-admin may edit it in the
 * supplier profile. The rate breakdown per badge:
 *
 *   %TC = %OW + %CO + %SSG      (%OW = 40 %, %CO+%SSG = 60 %)
 *
 *   Badge     %CO      %SSG
 *   Normal    25 %     35 %
 *   Pioneer   25 %     35 %
 *   Gold     37.5 %   22.5 %
 *   Silver   32.5 %   27.5 %
 *   Bronze    30 %     30 %
 */
class SettlementCalculatorService
{
    /** نرخِ پیش‌فرضِ کارمزدِ تراکنش (٪) — وقتی تنظیمِ سراسری ست نشده باشد. */
    private const TC_PERCENTAGE = 1.0;

    /**
     * نرخِ مؤثرِ %TC: از تنظیمِ سراسریِ قابل‌ویرایش (PriceSetting: tc_percentage) خوانده
     * می‌شود و در نبودِ آن به پیش‌فرضِ ۱٪ برمی‌گردد. static است تا از متدهای static و
     * instanceِ این سرویس یکسان قابل‌فراخوانی باشد. مقدار کش‌شده است (کشِ PriceSetting).
     */
    private static function tcPercentage(): float
    {
        return PriceSetting::getValueAsFloat(PriceSetting::CODE_TC_PERCENTAGE, self::TC_PERCENTAGE);
    }

    /**
     * نرخِ مؤثرِ %TC برای اسنپ‌شات روی سفارش.
     *
     * پرداخت باید همین مقدار را در `orders.commission_rate` ثبت کند و باسکول باید
     * همان را بخواند — نه اینکه دوباره از PriceSetting زنده بخواند. پیش از این
     * ProcessPreScalePaymentAction عددِ هاردکدِ `1.0` را ثبت می‌کرد و
     * WeighbridgeReconciler نرخ را زنده می‌خواند؛ اگر tc_percentage بین پرداخت و
     * باسکول تغییر می‌کرد، POOM با وزنِ کاملاً یکسان از POO فاصله می‌گرفت و یک
     * «کسری» کاذب می‌ساخت که واقعاً به خریدار عودت می‌داد و برای تأمین‌کننده بدهی
     * ثبت می‌کرد.
     */
    public function currentTcPercentage(): float
    {
        return self::tcPercentage();
    }

    /**
     * همان نرخ‌های getCommissionRates اما با یک %TC مشخص (اسنپ‌شاتِ سفارش) به‌جای
     * مقدارِ زندهٔ PriceSetting. هر محاسبهٔ *بعد از* پرداخت باید از این استفاده کند.
     *
     * @return array{
     *   ow_percent_of_tc: float, co_percent_of_tc: float, ssg_percent_of_tc: float,
     *   ow_final_rate: float, co_final_rate: float, ssg_final_rate: float,
     * }
     */
    public function getCommissionRatesForTc(?string $badgeCode, float $tcPercentage): array
    {
        $shares = $this->badgeShares($badgeCode);

        return [
            'ow_percent_of_tc' => $shares['ow'],
            'co_percent_of_tc' => $shares['co'],
            'ssg_percent_of_tc' => $shares['ssg'],
            'ow_final_rate' => (float) Money::mul($tcPercentage, Money::div($shares['ow'], '100')),
            'co_final_rate' => (float) Money::mul($tcPercentage, Money::div($shares['co'], '100')),
            'ssg_final_rate' => (float) Money::mul($tcPercentage, Money::div($shares['ssg'], '100')),
        ];
    }

    // =========================================================================
    // Public API
    // =========================================================================

    /**
     * Calculate commissions for the initial order placement snapshot.
     * Used by PlaceOrderAction / ProcessPreScalePaymentAction.
     *
     * @param  float  $subtotal  مبلغ کل بدون مالیات
     * @param  string|null  $badgeCode  کد نشان الیت یار (golden, silver, bronze, pioneer, null)
     * @return array{TC: float, OW: float, CO: float, SSG: float}
     */
    public function calculateCommissions(float $subtotal, ?string $badgeCode): array
    {
        $rates = $this->getCommissionRates($badgeCode);

        // bcmath throughout — no intermediate float accumulation. Commissions are
        // whole Rials, so the boundary rounds to 0 decimals (preserves prior round()).
        $TC = Money::mul($subtotal, Money::div(self::tcPercentage(), '100'));
        $OW = Money::mul($TC, Money::div($rates['ow_percent_of_tc'], '100'));
        $CO = Money::mul($TC, Money::div($rates['co_percent_of_tc'], '100'));
        $SSG = Money::mul($TC, Money::div($rates['ssg_percent_of_tc'], '100'));

        return [
            'TC' => Money::toFloat($TC, 0),
            'OW' => Money::toFloat($OW, 0),
            'CO' => Money::toFloat($CO, 0),
            'SSG' => Money::toFloat($SSG, 0),
        ];
    }

    /**
     * Calculate POO — Payment Of Owner (مبلغ پرداختی خریدار).
     *
     * Uses the initial weight (WBS) for the pre-scale payment, or
     * the actual weighbridge weight (WAS) for the modified POOM calculation.
     * The formula variant is selected by the supplier-contract flags.
     *
     * Four variants:
     *   +TC +VAT: [Trans + OP×W] × (1−%OW) × (1+VAT)
     *   +TC −VAT: [OP×W × (1−%OW) × (1+VAT)] + [Trans × (1−%OW)]
     *   −TC +VAT: [Trans + OP×W × (1−%OW)] × (1+VAT)
     *   −TC −VAT: [OP×W × (1−%OW) × (1+VAT)] + Trans
     *
     * @param  float  $trans  هزینه حمل و نقل
     * @param  float  $op  قیمت رسمی (OP)
     * @param  float  $weight  وزن (WBS قبل از باسکول، یا WAS بعد از باسکول)
     * @param  float  $owPercent  %OW به صورت درصد نهایی (مثال: ۰.۴ برای ۰.۴٪)
     * @param  float  $vatPercent  %VAT به صورت درصد (مثال: ۱۰ برای ۱۰٪)
     * @param  bool  $transCommission  آیا کمیسیون روی Trans اعمال می‌شود؟
     * @param  bool  $transVat  آیا VAT روی Trans اعمال می‌شود؟
     */
    public function calculatePOO(
        float $trans,
        float $op,
        float $weight,
        float $owPercent,
        float $vatPercent,
        bool $transCommission = true,
        bool $transVat = true,
    ): float {
        $owFactor = Money::percentSub($owPercent);   // 1 − %OW/100
        $vatFactor = Money::percentAdd($vatPercent);  // 1 + VAT/100
        $productBase = Money::mul($op, $weight);

        if ($transCommission && $transVat) {
            // +TC +VAT: [Trans + OP×W] × (1−%OW) × (1+VAT)
            return Money::toFloat(Money::mul(Money::mul(Money::add($trans, $productBase), $owFactor), $vatFactor));
        }

        if ($transCommission) {
            // +TC −VAT: [OP×W × (1−%OW) × (1+VAT)] + [Trans × (1−%OW)]
            return Money::toFloat(Money::add(
                Money::mul(Money::mul($productBase, $owFactor), $vatFactor),
                Money::mul($trans, $owFactor)
            ));
        }

        if ($transVat) {
            // −TC +VAT: [Trans + OP×W × (1−%OW)] × (1+VAT)
            return Money::toFloat(Money::mul(Money::add($trans, Money::mul($productBase, $owFactor)), $vatFactor));
        }

        // −TC −VAT: [OP×W × (1−%OW) × (1+VAT)] + Trans
        return Money::toFloat(Money::add(Money::mul(Money::mul($productBase, $owFactor), $vatFactor), $trans));
    }

    /**
     * Calculate PTS — Payment To Supplier (مبلغ پرداختی به سوپلایر).
     *
     * Uses the initial weight (WBS) for the pre-scale payment, or
     * the actual weighbridge weight (WAS) for the modified PTSM calculation.
     *
     * Four variants (same transport-flag logic as POO, but TC = OW+SSG+CO):
     *   +TC +VAT: [Trans + OP×W] × (1−TC) × (1+VAT)
     *   +TC −VAT: [OP×W × (1−TC) × (1+VAT)] + [Trans × (1−TC)]
     *   −TC +VAT: [Trans + OP×W × (1−TC)] × (1+VAT)
     *   −TC −VAT: [OP×W × (1−TC) × (1+VAT)] + Trans
     *
     * @param  float  $trans  هزینه حمل و نقل
     * @param  float  $op  قیمت رسمی
     * @param  float  $weight  وزن
     * @param  float  $owPercent  %OW نهایی
     * @param  float  $ssgPercent  %SSG نهایی
     * @param  float  $coPercent  %CO نهایی
     * @param  float  $vatPercent  %VAT
     * @param  bool  $transCommission  آیا کمیسیون روی Trans اعمال می‌شود؟
     * @param  bool  $transVat  آیا VAT روی Trans اعمال می‌شود؟
     */
    public function calculatePTS(
        float $trans,
        float $op,
        float $weight,
        float $owPercent,
        float $ssgPercent,
        float $coPercent,
        float $vatPercent,
        bool $transCommission = true,
        bool $transVat = true,
    ): float {
        $totalDeductionPercent = $owPercent + $ssgPercent + $coPercent;
        $deductFactor = Money::percentSub($totalDeductionPercent); // 1 − (OW+SSG+CO)/100
        $vatFactor = Money::percentAdd($vatPercent);               // 1 + VAT/100
        $productBase = Money::mul($op, $weight);

        if ($transCommission && $transVat) {
            // +TC +VAT: [Trans + OP×W] × (1−TC) × (1+VAT)
            return Money::toFloat(Money::mul(Money::mul(Money::add($trans, $productBase), $deductFactor), $vatFactor));
        }

        if ($transCommission) {
            // +TC −VAT: [OP×W × (1−TC) × (1+VAT)] + [Trans × (1−TC)]
            return Money::toFloat(Money::add(
                Money::mul(Money::mul($productBase, $deductFactor), $vatFactor),
                Money::mul($trans, $deductFactor)
            ));
        }

        if ($transVat) {
            // −TC +VAT: [Trans + OP×W × (1−TC)] × (1+VAT)
            return Money::toFloat(Money::mul(Money::add($trans, Money::mul($productBase, $deductFactor)), $vatFactor));
        }

        // −TC −VAT: [OP×W × (1−TC) × (1+VAT)] + Trans
        return Money::toFloat(Money::add(Money::mul(Money::mul($productBase, $deductFactor), $vatFactor), $trans));
    }

    /**
     * Return commission-rate percentages for a given badge.
     *
     * Returns two sets of rates:
     *  - `*_percent_of_tc`  — share as a % of TC (used in calculateCommissions)
     *  - `*_final_rate`     — absolute % of the invoice total (used in
     *                          calculatePOO / calculatePTS as owPercent / ssgPercent / coPercent)
     *
     * @return array{
     *   ow_percent_of_tc: float,
     *   co_percent_of_tc: float,
     *   ssg_percent_of_tc: float,
     *   ow_final_rate: float,
     *   co_final_rate: float,
     *   ssg_final_rate: float,
     * }
     */
    public function getCommissionRates(?string $badgeCode): array
    {
        $shares = $this->badgeShares($badgeCode);

        return [
            // Percentages relative to TC (for commission-amount calculations)
            'ow_percent_of_tc' => $shares['ow'],
            'co_percent_of_tc' => $shares['co'],
            'ssg_percent_of_tc' => $shares['ssg'],

            // Absolute percentages of invoice total (for POO / PTS formula inputs)
            // Example: TC=1 %, OW=40 % of TC → ow_final_rate = 0.4 %
            'ow_final_rate' => (float) Money::mul(self::tcPercentage(), Money::div($shares['ow'], '100')),
            'co_final_rate' => (float) Money::mul(self::tcPercentage(), Money::div($shares['co'], '100')),
            'ssg_final_rate' => (float) Money::mul(self::tcPercentage(), Money::div($shares['ssg'], '100')),
        ];
    }

    /**
     * Share-of-TC percentages per badge (always sum to 100 %).
     *
     * NOTE: the canonical badge codes seeded in the DB are gold / silver / bronze
     * (see database/seeders/ProductSeeder.php). 'golden' is accepted as an alias
     * so a legacy value never silently falls through to the default rate.
     *
     * @return array{ow: float, co: float, ssg: float}
     */
    private function badgeShares(?string $badgeCode): array
    {
        return match (strtolower(trim($badgeCode ?? ''))) {
            'gold', 'golden' => ['ow' => 40.0, 'co' => 37.5, 'ssg' => 22.5],
            'silver' => ['ow' => 40.0, 'co' => 32.5, 'ssg' => 27.5],
            'bronze' => ['ow' => 40.0, 'co' => 30.0, 'ssg' => 30.0],
            default => ['ow' => 40.0, 'co' => 25.0, 'ssg' => 35.0], // normal / pioneer
        };
    }

    /**
     * درصد مالیات مؤثر برای یک سفارش — **تنها منبع مجاز**.
     *
     * ⚠️ سه محل (`ProcessPreScalePaymentAction`, `ProcessReservationCompletionAction`,
     * `WeighbridgeReconciler`) هر کدام جداگانه `$order->vat_rate ?? 10.0` می‌نوشتند و
     * دو مشکل داشت:
     *
     * ۱. **`??` هیچ‌وقت کار نمی‌کرد.** ستون `orders.vat_rate` از نوع
     *    `decimal(5,2) NOT NULL DEFAULT 0.00` است و هیچ‌کس پیش از پرداخت پرش
     *    نمی‌کند، پس مقدارش `0.00` بود — نه `null`. یعنی مسیر خرید مستقیم و
     *    بازمحاسبهٔ باسکول POO/PTS را با **مالیات صفر** حساب می‌کردند، در حالی که
     *    سبد خرید از خریدار مالیات ۱۰٪ گرفته بود؛ و چون `sazeVat = gross × 0`
     *    می‌شد، هولد امانیِ مالیات (WVAT) هرگز ثبت نمی‌شد و اسناد O/P/Q عملاً
     *    صفر آزاد می‌کردند. (مسیر تکمیل رزرو `?:` نوشته بود و درست کار می‌کرد —
     *    یعنی یک سفارش بسته به مسیر پرداخت، مالیات متفاوتی می‌خورد.)
     *
     * ۲. **عدد ۱۰ هاردکد بود** در سه جا، در حالی که نرخ واقعی در
     *    `price_settings.vat_rate` است و همان چیزی است که پایپ‌لاین قیمت‌گذاری
     *    (`ApplyVat`) استفاده می‌کند. با تغییر نرخ، تسویه از سبد خرید واگرا می‌شد.
     *
     * اسنپ‌شاتِ واقعیِ روی سفارش (مقدار مثبت) مقدم است تا بازاجرای یک عملیات،
     * نرخِ لحظهٔ پرداخت را عوض نکند؛ در نبودش نرخ زندهٔ تنظیمات خوانده می‌شود، و
     * اگر آن هم ست نشده باشد `financial.default_vat_rate` (env: `DEFAULT_VAT_RATE`).
     *
     * ترتیب: اسنپ‌شاتِ سفارش ← تنظیماتِ زندهٔ قیمت ← پیش‌فرضِ کانفیگ.
     * هیچ عددی اینجا هاردکد نیست.
     */
    public function resolveVatRate(Order $order): float
    {
        $snapshot = (float) ($order->{Order::VAT_RATE} ?? 0);

        return $snapshot > 0
            ? $snapshot
            : PriceSetting::getValueAsFloat(
                PriceSetting::CODE_VAT_RATE,
                (float) config('financial.default_vat_rate', 10.0)
            );
    }

    /**
     * Split a VAT-inclusive gross amount into its net and VAT parts.
     *
     * Uses vat = gross × VAT / (100 + VAT) — the division acts on large operands,
     * so no precision is lost to a sub-unit multiplier (e.g. 1,100,000,000 with
     * 10% VAT → vat = 100,000,000 exactly).
     *
     * @return array{net: float, vat: float}
     */
    public function extractVat(float $gross, float $vatPercent): array
    {
        $vat = Money::toFloat(
            Money::div(Money::mul($gross, $vatPercent), Money::add('100', $vatPercent))
        );

        // net از vatِ *گردشده* مشتق می‌شود تا `net + vat === gross` دقیقاً برقرار باشد.
        // پیش از این هر دو مستقل گرد می‌شدند و مجموعشان می‌توانست تا ۰٫۰۱ ریال از
        // gross فاصله بگیرد — و چون WeighbridgeVatRouter کیف پول سازه را به اندازهٔ
        // gross بدهکار و WEY/WVAT را به اندازهٔ net و vat بستانکار می‌کند، همان
        // اختلاف به‌صورت یک عدم‌توازنِ زیرریالی در لجر دوطرفه می‌نشست.
        $net = Money::toFloat(Money::sub($gross, $vat));

        return [
            'net' => $net,
            'vat' => $vat,
        ];
    }
}
