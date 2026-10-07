<?php

namespace App\Services;

use App\Jobs\Notification\SendSmsJob;
use App\Models\SmsTemplate;
use App\Models\User;
use App\Models\UserNotification;

/**
 * یک رویداد → پیامک + اعلانِ درون‌برنامه‌ای (و از آنجا، پوش).
 *
 * ## چرا این کلاس هست
 *
 * شش لیسنر عیناً یک کار را می‌کردند: `SendSmsJob::dispatch($user->mobile, $msg)`
 * و بس. یعنی رویدادهای سفارش و مالی — مهم‌ترین چیزهایی که کاربر باید بداند —
 * **هیچ ردیفی در `user_notifications` نمی‌ساختند**: زنگوله‌ی اعلان داخل پنل
 * درباره‌ی سفارش‌ها همیشه خالی بود، و چون پوش از روی همان ردیف‌ها ساخته می‌شود،
 * پوش هم چیزی برای حمل نداشت.
 *
 * گذاشتنِ دو خطِ تکراری در هر شش لیسنر یعنی هفتمی که اضافه شود فراموش می‌شود.
 * یک نقطه، شش صداکننده.
 *
 * ⚠️ ترتیب عمدی است: **اول پیامک**. اگر ساختِ ردیف خطا بدهد، جاب دوباره اجرا
 * می‌شود و پیامک دوباره می‌رود — که پنجره‌ی ۲۵ ثانیه‌ایِ dedupe در
 * `SendSmsJob` همان را می‌گیرد. برعکسش چنین محافظی ندارد.
 *
 * ## دو ورودی
 *
 * - `template()` — قالبِ `sms_templates` (کلیدِ lang). راهِ درست برای هر پیامکی
 *   که کلید دارد: متنِ ویرایش‌شده‌ی ادمین، خاموش‌بودن و شرط‌ها همه اثر می‌کنند.
 *   بدنه‌ی زنگوله همان متنِ قالب است (`SmsTemplate::text`) — بدونِ شرط: خاموش‌کردنِ
 *   پیامک، اعلانِ داخلِ پنل را نمی‌کُشد.
 * - `toUser()` — متنِ خام؛ برای پیامی که (هنوز) قالب ندارد.
 */
class Notifier
{
    /**
     * @param  array<string, mixed>  $params
     */
    public static function template(User $user, string $subject, string $key, array $params = []): void
    {
        // «:first_name عزیز،» (دستورالعملِ پیامکِ مدیریت، دورِ ششم) — گیرنده را داریم، پس نامش را هم؛
        // متنِ زنگوله هم همان خطابِ پیامک را می‌گیرد.
        $params += ['first_name' => SmsTemplate::firstNameFor((string) $user->mobile)];

        $body = SmsTemplate::text($key, $params);

        if ($body === null) {
            return;
        }

        SendSmsJob::template($user->mobile, $key, $params);

        self::bell($user, $subject, $body);
    }

    public static function toUser(User $user, string $subject, string $message): void
    {
        SendSmsJob::dispatch($user->mobile, $message);

        self::bell($user, $subject, $message);
    }

    private static function bell(User $user, string $subject, string $body): void
    {
        // قلابِ `UserNotification::created` این را به `SendPushJob` می‌دهد.
        UserNotification::create([
            UserNotification::USER_ID => $user->id,
            UserNotification::SENDER_ID => null,
            UserNotification::BATCH_ID => null,
            UserNotification::SUBJECT => $subject,
            UserNotification::BODY => $body,
        ]);
    }
}
