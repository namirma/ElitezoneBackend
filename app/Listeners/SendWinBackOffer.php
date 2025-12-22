<?php

namespace App\Listeners;

use App\Events\CustomerSegmentChanged;
use App\Services\Marketing\CouponService;
use App\Models\CustomerStat; // فرض بر اینکه سگمنت‌ها اینجا ثابت تعریف شدن یا رشته هستن
use App\Models\UserProfile;
use Illuminate\Support\Facades\Log;

class SendWinBackOffer
{
    protected CouponService $couponService;

    /**
     * تزریق سرویس کوپن به لیسنر
     */
    public function __construct(CouponService $couponService)
    {
        $this->couponService = $couponService;
    }

    /**
     * Handle the event.
     */
    public function handle(CustomerSegmentChanged $event): void
    {
        // فقط وقتی واکنش نشان بده که مشتری "در خطر" (At Risk) شده
        // نکته: بهتره این رشته‌ها رو هم از ثابت‌های مدل CustomerStat بخونی
        if ($event->newSegment === 'at_risk' && $event->oldSegment === 'loyal') {

            $this->sendWinBackSms($event->user);
        }
        // سناریوی دوم: مشتری قهرمان شده (جایزه بده)
        elseif ($event->newSegment === 'champion') {

            $this->sendChampionReward($event->user);
        }
    }

    private function sendWinBackSms($user)
    {
        // ۱. ساخت کوپن (همان کد قبلی)
        $coupon = $this->couponService->createPersonalCoupon(
            $user,
            10,
            2,
            'Win-Back Offer (System Auto)'
        );

        // ۲. پیدا کردن نام کاربر از پروفایل
        // با استفاده از null-safe operator (علامت سوال) چک میکنیم پروفایل وجود داشته باشه
        $firstName = $user->profile?->{UserProfile::NAME} ?? '';
        $lastName  = $user->profile?->{UserProfile::FAMILY} ?? '';

        // اگر اسم و فامیل خالی بود، بنویس "کاربر"
        $fullName = trim("$firstName $lastName") ?: 'کاربر';

        // ۳. ارسال پیامک با نام درست
        Log::info("📨 [SMS SENT] To: {$user->mobile} | Msg: مهندس {$fullName} عزیز، دلمون تنگ شده! با کد {$coupon->code} برگرد و ۱۰٪ تخفیف بگیر.");
    }

    private function sendChampionReward($user)
    {
        Log::info("🏆 [CHAMPION] User {$user->name} is now a Champion! Sending VIP gift.");
    }
}
