<?php

declare(strict_types=1);

namespace App\Services\Financial;

class SettlementCalculatorService
{
    // درصد کارمزد کل (1 درصد از مبلغ ساب‌توتال)
    private const TC_PERCENTAGE = 1.0;

    /**
     * متد ۱: محاسبه ریز مبالغ کارمزد برای ثبت اولیه سفارش
     * (استفاده در PlaceOrderAction)
     * * @param float $subtotal مبلغ کل بدون مالیات
     * @param string|null $badgeCode کد نشان مشاور
     * @return array [TC, OW, CO, SSG] (مبالغ ریالی)
     */
    public function calculateCommissions(float $subtotal, ?string $badgeCode): array
    {
        // 1. دریافت درصدهای تقسیم (نسبت به 1 درصد کل)
        $rates = $this->getCommissionRates($badgeCode);

        // 2. محاسبه مبلغ کل کارمزد (TC)
        $TC_Amount = $subtotal * (self::TC_PERCENTAGE / 100);

        // 3. محاسبه مبالغ سهم‌ها
        // فرمول: مبلغ کل کارمزد * درصد سهم
        // نکته: درصدهای getCommissionRates بر اساس سهم از TC هستند (نه کل مبلغ)
        // اما برای سهولت، ما فرض می‌کنیم rates مستقیم درصد از TC را برمی‌گردانند (مثلا 40 برای 40%)

        $OW = $TC_Amount * ($rates['ow_percent_of_tc'] / 100);
        $CO = $TC_Amount * ($rates['co_percent_of_tc'] / 100);
        $SSG = $TC_Amount * ($rates['ssg_percent_of_tc'] / 100);

        return [
            'TC'  => round($TC_Amount),
            'OW'  => round($OW),
            'CO'  => round($CO),
            'SSG' => round($SSG),
        ];
    }

    /**
     * متد ۲: محاسبه POO (مبلغ پرداختی خریدار)
     * (استفاده در ProcessWeighbridgeResultAction)
     * فرمول: [Trans + OP x WBS] x (1-%OW) x (1+VAT)
     * * نکته: owPercent باید درصد واقعی از کل باشد.
     * چون TC یک درصد است و OW چهل درصدِ TC است، پس OW نهایی 0.4 درصد است.
     * ورودی این متد باید 0.4 باشد.
     */
    public function calculatePOO(float $trans, float $op, float $wbs, float $owPercent, float $vatPercent): float
    {
        $base = $trans + ($op * $wbs);

        // اعمال تخفیف سهم مالک
        $afterDiscount = $base * (1 - ($owPercent / 100));

        // اعمال مالیات بر ارزش افزوده
        return $afterDiscount * (1 + ($vatPercent / 100));
    }

    /**
     * متد ۳: محاسبه PTS (مبلغ پرداختی به سوپلایر)
     * (استفاده در ProcessWeighbridgeResultAction)
     * فرمول: [Trans + OP x WBS] x (1 - (%OW + %SSG + %CO)) x (1+VAT)
     * * نکته: مجموع درصدها همان TC (یک درصد) می‌شود.
     */
    public function calculatePTS(
        float $trans, float $op, float $wbs,
        float $owPercent, float $ssgPercent, float $coPercent, float $vatPercent
    ): float {
        $base = $trans + ($op * $wbs);

        // کسر کل کارمزدها از مبلغ پایه
        $totalDeduction = ($owPercent + $ssgPercent + $coPercent) / 100;

        $share = $base * (1 - $totalDeduction);

        // اعمال مالیات
        return $share * (1 + ($vatPercent / 100));
    }

    /**
     * متد کمکی: دریافت درصدهای دقیق بر اساس بج
     * خروجی این متد هم برای محاسبه ریالی استفاده می‌شود و هم برای پاس دادن به متدهای باسکول
     * * خروجی به صورت "درصد از کل مبلغ" تنظیم می‌شود تا در فرمول‌های بالا کار کند.
     * مثلا اگر TC یک درصد است و OW چهل درصدِ آن است، خروجی ow_rate برابر 0.4 خواهد بود.
     */
    public function getCommissionRates(?string $badgeCode): array
    {
        $badge = strtolower(trim($badgeCode ?? ''));

        // درصدهای سهم از خودِ کارمزد (Share of TC)
        $shareOfTC = [
            'ow' => 40.0, // همیشه 40 درصد از TC
            'co' => 0.0,
            'ssg' => 0.0
        ];

        switch ($badge) {
            case 'golden':
                // طلایی: CO = 37.5%, SSG = 22.5%
                $shareOfTC['co'] = 12.5 + 25; // 37.5
                $shareOfTC['ssg'] = 22.5;
                break;

            case 'silver':
                // نقره‌ای: CO = 32.5%, SSG = 27.5%
                $shareOfTC['co'] = 7.5 + 25; // 32.5
                $shareOfTC['ssg'] = 27.5;
                break;

            case 'bronze':
                // برنزی: CO = 30%, SSG = 30%
                $shareOfTC['co'] = 5 + 25; // 30
                $shareOfTC['ssg'] = 30;
                break;

            case 'pioneer':
            default:
                // عادی: CO = 25%, SSG = 35%
                $shareOfTC['co'] = 25.0;
                $shareOfTC['ssg'] = 35.0;
                break;
        }

        // تبدیل به "درصد نهایی از مبلغ کل" برای استفاده در فرمول‌های باسکول
        // مثال: اگر TC=1% و OW_Share=40% -> OW_Final = 0.4%
        return [
            // درصدهای نسبی (برای محاسبه Amount در PlaceOrder)
            'ow_percent_of_tc' => $shareOfTC['ow'],
            'co_percent_of_tc' => $shareOfTC['co'],
            'ssg_percent_of_tc' => $shareOfTC['ssg'],

            // درصدهای مطلق (برای فرمول‌های calculatePOO و calculatePTS)
            'ow_final_rate' => self::TC_PERCENTAGE * ($shareOfTC['ow'] / 100),
            'co_final_rate' => self::TC_PERCENTAGE * ($shareOfTC['co'] / 100),
            'ssg_final_rate' => self::TC_PERCENTAGE * ($shareOfTC['ssg'] / 100),
        ];
    }
}
