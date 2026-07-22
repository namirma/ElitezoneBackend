<?php

declare(strict_types=1);

namespace App\Services\Financial;

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
        $badge = strtolower(trim($badgeCode ?? ''));

        // Share-of-TC percentages (always sum to 100 %).
        // NOTE: the canonical badge codes seeded in the DB are gold / silver / bronze
        // (see database/seeders/ProductSeeder.php). 'golden' is accepted as an alias
        // so a legacy value never silently falls through to the default rate.
        $shares = match ($badge) {
            'gold', 'golden' => ['ow' => 40.0, 'co' => 37.5, 'ssg' => 22.5],
            'silver' => ['ow' => 40.0, 'co' => 32.5, 'ssg' => 27.5],
            'bronze' => ['ow' => 40.0, 'co' => 30.0, 'ssg' => 30.0],
            default => ['ow' => 40.0, 'co' => 25.0, 'ssg' => 35.0], // normal / pioneer
        };

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
     * VAT-extraction multiplier for a VAT-inclusive amount:
     *   vatMultiplier = (VAT/100) / (1 + VAT/100)
     *
     * For a gross (VAT-inclusive) amount G, the embedded VAT is G × vatMultiplier
     * and the net is G − VAT. Computed with bcmath so repeating decimals
     * (e.g. 10% → 1/11) don't accumulate float error across the settlement legs.
     */
    public function vatMultiplier(float $vatPercent): float
    {
        // Algebraically (VAT/100)/(1+VAT/100) == VAT/(100+VAT) — the latter avoids a
        // tiny truncated intermediate, staying numerically stable.
        return (float) Money::div($vatPercent, Money::add('100', $vatPercent));
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
        $vat = Money::div(Money::mul($gross, $vatPercent), Money::add('100', $vatPercent));
        $net = Money::sub($gross, $vat);

        return [
            'net' => Money::toFloat($net),
            'vat' => Money::toFloat($vat),
        ];
    }
}
