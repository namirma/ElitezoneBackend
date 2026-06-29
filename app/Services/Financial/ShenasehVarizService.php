<?php

namespace App\Services\Financial;

/**
 * سرویس تولید و اعتبارسنجی شناسه واریز
 *
 * الگوریتم: ۱-۱ (جمع اعداد ۱ رقمی)
 * ──────────────────────────────────────
 * شناسه واریز = شماره سریال + رقم کنترلی
 *
 * رقم کنترلی:
 *   الف) تبدیل هر کاراکتر سریال به کد ASCII
 *   ب)  کسر ۴۸ از هر کد ASCII
 *   ج)  جمع کلیه اعداد
 *   د)  [حاصل جمع MOD 10]
 *
 * مثال برای کد ملی ۱۲۷۳۳۴۸۰۲۸:
 *   sum = 1+2+7+3+3+4+8+0+2+8 = 38
 *   38 MOD 10 = 8
 *   شناسه واریز = 12733480288
 *
 * نکات:
 * - برای کد ملی (اعداد خالص): ASCII(رقم) - 48 = خود رقم → sum = sum of digits
 * - سریال می‌تواند شامل کاراکترهای غیرعددی (مثل -) باشد؛ در کد ملی همه رقم هستند
 * - شناسه واریز نهایی = ۱۰ رقم کد ملی + ۱ رقم کنترل = ۱۱ کاراکتر
 */
class ShenasehVarizService
{
    /**
     * تولید شناسه واریز از کد ملی کاربر (الگوریتم ۱-۱)
     *
     * @param  string  $nationalCode  کد ملی ۱۰ رقمی (بدون صفر پیشرو اختیاری)
     * @return string شناسه واریز ۱۱ کاراکتری
     */
    public function generate(string $nationalCode): string
    {
        $controlDigit = $this->calculateControlDigit($nationalCode);

        return $nationalCode.$controlDigit;
    }

    /**
     * اعتبارسنجی یک شناسه واریز
     *
     * @param  string  $shenaseh  شناسه واریز (سریال + رقم کنترل)
     */
    public function validate(string $shenaseh): bool
    {
        $len = strlen($shenaseh);

        // حداقل: ۲ کاراکتر سریال + ۱ رقم کنترل
        if ($len < 3) {
            return false;
        }

        $serial = substr($shenaseh, 0, -1);
        $controlGiven = (int) substr($shenaseh, -1);
        $controlCalc = $this->calculateControlDigit($serial);

        return $controlGiven === $controlCalc;
    }

    /**
     * استخراج کد ملی (سریال) از شناسه واریز
     * آخرین کاراکتر = رقم کنترلی است
     *
     * @return string کد ملی (بدون رقم کنترل)
     */
    public function extractNationalCode(string $shenaseh): string
    {
        return substr($shenaseh, 0, -1);
    }

    /**
     * محاسبه رقم کنترلی برای یک سریال (الگوریتم ۱-۱: MOD 10)
     */
    private function calculateControlDigit(string $serial): int
    {
        $sum = 0;
        foreach (str_split($serial) as $char) {
            // ASCII(char) - 48: برای ارقام ۰-۹ مساوی خود رقم است
            // برای کاراکترهای دیگر (مثل -) مقدار منفی می‌شود
            $sum += (ord($char) - 48);
        }

        return $sum % 10;
    }
}
