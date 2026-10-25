<?php

namespace App\Services;

use App\Jobs\Notification\SendSmsJob;
use App\Models\User;
use App\Models\UserNotification;

/**
 * یک رویداد → پیامک + اعلانِ درون‌برنامه‌ای (و از آنجا، پوش).
 *
 * ## چرا این کلاس هست
 *
 * شش لیسنر عیناً یک کار را می‌کردند: `SendSmsJob::dispatch($user->mobile, $msg)`
 * و بس. یعنی رویدادهای سفارش و مالی — مهم‌ترین چیزهایی که کاربر باید بداند —
 * **هیچ ردیفی در `user_notifications` نمی‌ساختند**: زنگولهٔ اعلان داخل پنل
 * دربارهٔ سفارش‌ها همیشه خالی بود، و چون پوش از روی همان ردیف‌ها ساخته می‌شود،
 * پوش هم چیزی برای حمل نداشت.
 *
 * گذاشتنِ دو خطِ تکراری در هر شش لیسنر یعنی هفتمی که اضافه شود فراموش می‌شود.
 * یک نقطه، شش صداکننده.
 *
 * ⚠️ ترتیب عمدی است: **اول پیامک**. اگر ساختِ ردیف خطا بدهد، جاب دوباره اجرا
 * می‌شود و پیامک دوباره می‌رود — که پنجرهٔ ۲۵ ثانیه‌ایِ dedupe در
 * `SendSmsJob` همان را می‌گیرد. برعکسش چنین محافظی ندارد.
 */
class Notifier
{
    public static function toUser(User $user, string $subject, string $message): void
    {
        SendSmsJob::dispatch($user->mobile, $message);

        // قلابِ `UserNotification::created` این را به `SendPushJob` می‌دهد.
        UserNotification::create([
            UserNotification::USER_ID => $user->id,
            UserNotification::SENDER_ID => null,
            UserNotification::BATCH_ID => null,
            UserNotification::SUBJECT => $subject,
            UserNotification::BODY => $message,
        ]);
    }
}
