<?php

namespace App\Actions\Profile;

use App\Jobs\Notification\SendSmsJob;
use App\Models\SmsTemplate;
use App\Models\User;
use App\Models\UserNotification;

/**
 * وقتی حکمِ «الیت‌یارِ تأییدشده» برای یک کاربر عوض می‌شود: پیامک + اعلانِ
 * درون‌برنامه‌ای + ردِ ممیزی. دستورِ علی (۱۴۰۵/۰۶/۳۱): هیچ چیزِ تأییدی بی‌صدا
 * عوض نمی‌شود.
 *
 * ورودی «قبل/بعد»ِ حکم است، نه عضویت یا پرچمِ مدارک — چون تنها چیزی که برای
 * کاربر معنی دارد همان حکم است (`User::isVerifiedEliteYAR()`): تأییدِ دستیِ
 * مدارکِ کسی که از راهِ سازه ۹۰ تأییدشده بود، چیزی را عوض نمی‌کند و پیامک ندارد.
 *
 * صداکننده‌ها: `SyncUserBadgeAction` (تغییرِ عضویتِ سازه ۹۰) و
 * `AdminUserController::verifyEliteYAR/unverifyEliteYAR`. خاموش/روشن‌کردنِ یک
 * روش از تنظیمات عمداً پیامک ندارد — یک تصمیمِ سیاستی است، نه رویدادِ یک کاربر.
 */
class NotifyEliteYARVerificationChange
{
    public function execute(User $user, bool $wasVerified, bool $isVerified, string $source): void
    {
        if ($wasVerified === $isVerified) {
            return;
        }

        // «تأیید شدید» برای کسی که الیت یار نیست بی‌معنی است (کارفرمای عضوِ سازه ۹۰).
        if (! $user->hasRole('elite_yar')) {
            return;
        }

        $mobile = (string) $user->{User::MOBILE};

        // دو فراخوانیِ literal — `SmsCatalogCompletenessTest` کلیدها را با grep می‌بیند.
        if ($isVerified) {
            SendSmsJob::template($mobile, 'elite_yar_verified');
            $body = SmsTemplate::text('elite_yar_verified');
            $subject = __('global.eliteYARVerifiedSubject');
            $log = 'کاربر به‌عنوان الیت یار تأیید شد.';
        } else {
            SendSmsJob::template($mobile, 'elite_yar_unverified');
            $body = SmsTemplate::text('elite_yar_unverified');
            $subject = __('global.eliteYARUnverifiedSubject');
            $log = 'تأیید الیت یار کاربر برداشته شد.';
        }

        UserNotification::create([
            UserNotification::USER_ID => $user->id,
            UserNotification::SUBJECT => $subject,
            UserNotification::BODY => $body,
        ]);

        activity()
            ->performedOn($user)
            ->causedBy(auth()->user())
            ->withProperties(['is_verified' => $isVerified, 'source' => $source])
            ->log($log);
    }
}
