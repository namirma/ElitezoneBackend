<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * تقویم ساعت کاری برای SLA تیکتینگ.
 *
 * ساعت شروع/پایان روز کاری و روزهای تعطیل هفته از config('ticketing.business_hours')
 * خوانده می‌شود (ایران: جمعه تعطیل). ساعت‌های SLA فقط داخل بازه کاری مصرف می‌شوند؛
 * تیکتی که آخر وقت پنجشنبه ثبت شود، مهلتش به شنبه منتقل می‌شود.
 */
class BusinessHours
{
    /**
     * افزودن N ساعت «کاری» به یک لحظه — دقیقه‌به‌دقیقه پیش نمی‌رود؛ بازه‌ای می‌پرد.
     */
    public static function addWorkingHours(Carbon $start, float $hours): Carbon
    {
        $config = config('ticketing.business_hours');

        // تقویم خاموش → همان رفتار wall-clock سابق (BC برای envهای بدون تنظیم)
        if (! ($config['enabled'] ?? false)) {
            return $start->copy()->addMinutes((int) round($hours * 60));
        }

        $dayStart = (int) ($config['start_hour'] ?? 9);
        $dayEnd = (int) ($config['end_hour'] ?? 17);
        /** @var list<int> $weekend روزهای تعطیل — Carbon dayOfWeek (جمعه = 5) */
        $weekend = $config['weekend_days'] ?? [Carbon::FRIDAY];

        $remaining = (int) round($hours * 60); // دقیقه کاری باقی‌مانده
        $cursor = $start->copy();

        // اگر خارج از ساعت کاری است، به ابتدای اولین بازه کاری بعدی برو
        $cursor = self::snapToWorkingTime($cursor, $dayStart, $dayEnd, $weekend);

        while ($remaining > 0) {
            $endOfDay = $cursor->copy()->setTime($dayEnd, 0);
            $available = $cursor->diffInMinutes($endOfDay, false);

            if ($available >= $remaining) {
                return $cursor->addMinutes($remaining);
            }

            $remaining -= max(0, $available);
            // ابتدای روز کاری بعد
            $cursor = self::snapToWorkingTime(
                $cursor->copy()->addDay()->setTime($dayStart, 0),
                $dayStart, $dayEnd, $weekend
            );
        }

        return $cursor;
    }

    private static function snapToWorkingTime(Carbon $moment, int $dayStart, int $dayEnd, array $weekend): Carbon
    {
        $cursor = $moment->copy();

        // روز تعطیل یا بعد از پایان ساعت کاری → ابتدای روز کاری بعد
        while (in_array($cursor->dayOfWeek, $weekend, true) || $cursor->hour >= $dayEnd) {
            $cursor = $cursor->addDay()->setTime($dayStart, 0);
            while (in_array($cursor->dayOfWeek, $weekend, true)) {
                $cursor = $cursor->addDay();
            }
        }

        // قبل از شروع ساعت کاری → همان روز، ساعت شروع
        if ($cursor->hour < $dayStart) {
            $cursor = $cursor->setTime($dayStart, 0);
        }

        return $cursor;
    }
}
