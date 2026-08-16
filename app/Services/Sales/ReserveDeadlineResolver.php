<?php

declare(strict_types=1);

namespace App\Services\Sales;

use App\Models\SupplierProfile;
use Carbon\CarbonInterface;

/**
 * مهلتِ یک رزروِ تازه — الحاقیه‌ی ۱، تغییر ۲ و ۳.
 *
 * قاعده:
 *
 *   remaining  = supplier.reserve_time − now
 *   autoExtend = supplier.reserve_auto_extend_minutes   (پیش‌فرض ۰)
 *
 *   if autoExtend > 0 AND remaining < autoExtend:
 *       deadline = now + autoExtend
 *   else:
 *       deadline = supplier.reserve_time
 *
 * سه نکته‌ی الزامیِ الحاقیه، که هر سه اینجا رعایت شده‌اند:
 *
 * ۱. تمدید خودکار فقط `orders.reserve_deadline` را بالا می‌برد، **نه**
 *    `supplier_profiles.reserve_time` را. آن یک مقدارِ سراسری است و روی سبدِ
 *    همه‌ی مشتریان اثر دارد؛ جابه‌جا کردنش به‌خاطر یک رزرو یعنی مهلتِ سبدِ
 *    بقیه هم بی‌صدا عوض شود. قیمت در لحظه‌ی رزرو قفل می‌شود، پس یک رزرو
 *    می‌تواند از اعتبارِ قیمت‌ها عمرِ بیشتری داشته باشد.
 *
 * ۲. پیش‌فرضِ `autoExtend = 0` یعنی هیچ حاشیه‌ای داده نمی‌شود — خریداری که ده
 *    دقیقه مانده رزرو کند، ده دقیقه فرصت دارد. این عمدی است.
 *
 * ۳. آستانه‌ی هشدار (`reserve_warning_threshold_minutes`) اینجا **هیچ نقشی
 *    ندارد**. آن فقط تعیین می‌کند کِی به خریدار پیام داده شود.
 *
 * پوششِ تست: tests/Feature/Order/LateReservationTest.php
 */
final class ReserveDeadlineResolver
{
    public function __construct(
        private readonly SalesSettings $settings,
    ) {}

    /**
     * مهلتِ رزرو، یا `null` اگر تأمین‌کننده پنجره‌ی قیمتِ معتبر نداشته باشد.
     *
     * `null` یعنی «اصلاً قابل رزرو نیست» و صداکننده باید ۴۲۲ بدهد — قفل‌کردنِ
     * قیمتی که وجود ندارد بی‌معناست.
     */
    public function resolve(?SupplierProfile $supplier): ?CarbonInterface
    {
        if (! $supplier || ! $supplier->hasValidPriceWindow()) {
            return null;
        }

        $reserveTime = $supplier->{SupplierProfile::RESERVE_TIME};
        $autoExtend = max(0, (int) $supplier->{SupplierProfile::RESERVE_AUTO_EXTEND_MINUTES});

        if ($autoExtend === 0) {
            return $reserveTime;
        }

        // «کمتر از autoExtend باقی مانده؟» با مقایسه‌ی دو لحظه و نه
        // `diffInMinutes` — کاربن ۲ و ۳ علامتِ متفاوتی برمی‌گردانند و همین تله
        // یک بار در `CartParkService` خورده بود.
        return $reserveTime->lessThan(now()->addMinutes($autoExtend))
            ? now()->addMinutes($autoExtend)
            : $reserveTime;
    }

    /**
     * مهلتِ ارفاق برای این رزرو، به ساعت — الحاقیه‌ی ۱، تغییر ۳.
     *
     * override تأمین‌کننده بر تنظیمِ بک‌آفیس مقدم است. `null` یعنی «تصمیم
     * نگرفته‌ام» و به پیش‌فرض می‌افتد؛ صفر یک انتخابِ معتبرِ دیگر است («هیچ
     * ارفاقی نده») و نباید با `null` یکی شمرده شود — به همین دلیل `??` و نه
     * `?:`.
     */
    public function graceHoursFor(?SupplierProfile $supplier): int
    {
        return $supplier?->{SupplierProfile::RESERVE_GRACE_HOURS}
            ?? (int) $this->settings->reserveGrace()->totalHours;
    }
}
