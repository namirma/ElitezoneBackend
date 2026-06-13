<?php

namespace App\Listeners\User;

use App\Events\User\CustomerSegmentChanged;
use App\Models\CustomerStat;
use App\Models\UserProfile;
use App\Services\Marketing\CouponService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

class SendWinBackOffer implements ShouldQueue
{
    use InteractsWithQueue;

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
        // وقتی مشتریِ وفادار/قهرمان به وضعیت‌های افتِ تعامل می‌افتد، پیشنهاد بازگشت بفرست.
        // مقایسه با ثابت‌های مدل انجام می‌شود تا با تغییر نام سگمنت، لیسنر بی‌صدا خاموش نشود.
        $decliningFrom = [CustomerStat::SEGMENT_LOYAL, CustomerStat::SEGMENT_CHAMPION];
        $decliningTo = [CustomerStat::SEGMENT_AT_RISK, CustomerStat::SEGMENT_HIBERNATING, CustomerStat::SEGMENT_LOST];

        if (in_array($event->oldSegment, $decliningFrom, true) && in_array($event->newSegment, $decliningTo, true)) {
            $this->sendWinBackSms($event->user);
        } elseif ($event->newSegment === CustomerStat::SEGMENT_CHAMPION) {
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
        $lastName = $user->profile?->{UserProfile::FAMILY} ?? '';

        // اگر اسم و فامیل خالی بود، بنویس "کاربر"
        $fullName = trim("$firstName $lastName") ?: 'کاربر';

        // ۳. ارسال پیامک با نام درست — موبایل در لاگ ماسک می‌شود (PII).
        Log::info('[WIN-BACK SMS] to '.$this->maskMobile($user->mobile)." | coupon {$coupon->code}");
    }

    private function sendChampionReward($user)
    {
        Log::info('[CHAMPION] user #'.$user->id.' reached Champion segment; sending VIP gift.');
    }

    private function maskMobile(?string $mobile): string
    {
        if (empty($mobile) || strlen($mobile) < 7) {
            return '***';
        }

        return substr($mobile, 0, 4).str_repeat('*', strlen($mobile) - 7).substr($mobile, -3);
    }
}
