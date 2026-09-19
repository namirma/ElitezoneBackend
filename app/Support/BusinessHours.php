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

        // کلِ حساب‌وکتاب در زمانِ **محلیِ کسب‌وکار** انجام می‌شود و نتیجه به منطقه‌ی
        // زمانیِ ورودی برمی‌گردد.
        //
        // «۹ تا ۱۷» و «جمعه تعطیل» مفاهیمِ محلی‌اند، ولی `app.timezone` روی UTC است
        // و `TicketService` با `now()` صدا می‌زند. پس مقایسه‌ی `$cursor->hour` با
        // `start_hour` عملاً ساعتِ UTC را با ساعتِ کاریِ ایران می‌سنجید: تیکتی که
        // ۲۰:۰۰ تهران (۱۶:۳۰ UTC) ثبت می‌شد «داخل ساعت کاری» شمرده می‌شد و مهلتش
        // نیمه‌شب می‌افتاد، و تیکتِ ۱۱:۰۰ تهران «پیش از ۹» دیده می‌شد.
        //
        // برگرداندن به منطقه‌ی اولیه لازم است چون خروجی مستقیم در `sla_due_at`
        // ذخیره می‌شود و بقیه‌ی سیستم UTC می‌نویسد.
        $originalTz = $start->getTimezone();
        $businessTz = (string) ($config['timezone'] ?? 'Asia/Tehran');

        $remaining = (int) round($hours * 60); // دقیقه کاری باقی‌مانده
        $cursor = $start->copy()->setTimezone($businessTz);

        // اگر خارج از ساعت کاری است، به ابتدای اولین بازه کاری بعدی برو
        $cursor = self::snapToWorkingTime($cursor, $dayStart, $dayEnd, $weekend);

        while ($remaining > 0) {
            $endOfDay = $cursor->copy()->setTime($dayEnd, 0);
            // `(int)`: Carbon 3 اعشاری برمی‌گرداند و `$remaining` دقیقه‌ی صحیح است.
            $available = (int) $cursor->diffInMinutes($endOfDay, false);

            if ($available >= $remaining) {
                return $cursor->addMinutes($remaining)->setTimezone($originalTz);
            }

            $remaining -= max(0, $available);
            // ابتدای روز کاری بعد
            $cursor = self::snapToWorkingTime(
                $cursor->copy()->addDay()->setTime($dayStart, 0),
                $dayStart, $dayEnd, $weekend
            );
        }

        return $cursor->setTimezone($originalTz);
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
