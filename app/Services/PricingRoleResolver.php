<?php

namespace App\Services;

use App\Models\User;

/**
 * گروهِ فیلترِ قیمتِ فعال برای یک کاربر.
 *
 * پرسشِ ۱ مدیریت (۱۴۰۵/۰۷/۱۳، گزینه‌ی الف): «هر الیت‌یارِ تأییدشده و هر کارفرمایی
 * که الیت‌یارِ تأییدشده دارد قیمتِ با تخفیف می‌بیند.» تا پیش از این، این‌جا نقش‌های
 * قدیمیِ مهندس/پیمانکار و پرچمِ `employer_profiles.has_elite_yar` (که هیچ
 * نویسنده‌ای نداشت) خوانده می‌شد؛ الیت‌یارِ تازه‌ی سازه ۹۰ و کارفرمایی که الیت‌یار
 * تأییدشده دارد هر دو قیمتِ بی‌تخفیف می‌دیدند، و الیت‌یارِ قدیمیِ بی‌لایسنس
 * (هولد) همچنان تخفیف می‌گرفت.
 *
 * تنها محمول: `User::qualifiesForEliteYARPricing()`.
 */
class PricingRoleResolver
{
    public const WITH_ELITE_YAR = 'engineer_with_elite_yar';

    public const WITHOUT_ELITE_YAR = 'employer_without_elite_yar';

    public function resolve(User $user): string
    {
        $user = $user->fresh(['roles']) ?? $user;

        return $user->qualifiesForEliteYARPricing() ? self::WITH_ELITE_YAR : self::WITHOUT_ELITE_YAR;
    }
}
