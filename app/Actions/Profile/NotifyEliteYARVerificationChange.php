<?php

namespace App\Actions\Profile;

use App\Jobs\Notification\SendSmsJob;
use App\Models\EmployerEliteYAR;
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
        // `isEliteYAR()`، نه فقط نقشِ elite_yar: الیت‌یارهای قدیمیِ engineer/contractor هم هولد می‌شوند.
        if (! $user->isEliteYAR()) {
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

        $employerIds = $this->notifyEmployers($user, $isVerified);

        activity()
            ->performedOn($user)
            ->causedBy(auth()->user())
            ->withProperties(['is_verified' => $isVerified, 'source' => $source, 'notified_employer_ids' => $employerIds])
            ->log($log);
    }

    /**
     * مدیریت، دورِ پنجم، پرسشِ ۶ (۱۴۰۵/۰۷/۱۴): هولد/رفعِ هولدِ الیت‌یار به کارفرماهای تأییدشده‌اش
     * خبر داده می‌شود — هولد یعنی تخفیفِ خریدشان با این الیت‌یار از همین حالا قطع است.
     *
     * @return list<int> کارفرماهایی که خبر گرفتند (برای ردِ ممیزی)
     */
    private function notifyEmployers(User $eliteYAR, bool $isVerified): array
    {
        $employers = $eliteYAR->associatedEmployers()
            ->wherePivot(EmployerEliteYAR::STATUS, EmployerEliteYAR::STATUS_APPROVED)
            ->get();

        if ($employers->isEmpty()) {
            return [];
        }

        $eliteYAR->loadMissing('profile');
        $params = ['elite_yar_name' => $eliteYAR->profile->name ?? $eliteYAR->{User::MOBILE}];

        foreach ($employers as $employer) {
            $mobile = (string) $employer->getAttribute(User::MOBILE);

            // دو فراخوانیِ literal — `SmsCatalogCompletenessTest` کلیدها را با grep می‌بیند.
            if ($isVerified) {
                SendSmsJob::template($mobile, 'elite_yar_restored_employer', $params);
                $body = SmsTemplate::text('elite_yar_restored_employer', $params);
                $subject = __('global.eliteYARRestoredEmployerSubject');
            } else {
                SendSmsJob::template($mobile, 'elite_yar_hold_employer', $params);
                $body = SmsTemplate::text('elite_yar_hold_employer', $params);
                $subject = __('global.eliteYARHoldEmployerSubject');
            }

            UserNotification::create([
                UserNotification::USER_ID => $employer->getKey(),
                UserNotification::SUBJECT => $subject,
                UserNotification::BODY => $body,
            ]);
        }

        return $employers->pluck('id')->map(fn ($id) => (int) $id)->values()->all();
    }
}
