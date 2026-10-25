<?php

namespace App\Services;

use App\Models\DeviceToken;
use Illuminate\Support\Facades\Log;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;
use Throwable;

/**
 * ارسالِ نوتیفیکیشنِ پوش به همهٔ دستگاه‌های یک کاربر، روی استانداردِ Web Push.
 *
 * سه حالتِ «ارسال نشد ولی خطا نیست» عمداً `true` برمی‌گردانند تا جابِ صف
 * retry/fail نشود (دقیقاً مثل `SmsService::send`):
 *   ۱. کلیدهای VAPID تنظیم نشده‌اند
 *   ۲. محیط غیرپروداکشن و کاربر خارج از allowlist
 *   ۳. کاربر هیچ اشتراکِ فعالی ندارد (اپ را نصب/اجازه نداده) — حالتِ اکثریت
 *
 * فقط شکستِ واقعیِ تحویل `false` می‌دهد، تا `SendPushJob` دوباره تلاش کند.
 */
class PushService
{
    /**
     * سقفِ کاراکترِ عنوان و متن. عددها از سقفِ ~۴ کیلوبایتیِ سرویس‌های پوش
     * می‌آیند (با حاشیه برای سربارِ رمزگذاری و فارسیِ چندبایتی)، نه از سلیقه.
     */
    private const TITLE_MAX = 120;

    private const BODY_MAX = 500;

    private string $subject;

    private string $publicKey;

    private string $privateKey;

    public function __construct()
    {
        // پیش‌فرضِ '' لازم است: در محیط تست env وجود ندارد و property از نوع
        // string با null، TypeError می‌دهد.
        $this->subject = (string) config('services.push.vapid.subject', '');
        $this->publicKey = (string) config('services.push.vapid.public_key', '');
        $this->privateKey = (string) config('services.push.vapid.private_key', '');
    }

    /**
     * @param  array<string, mixed>  $data  دادهٔ ساختاریِ همراه (لینکِ مقصد و…)
     */
    public function sendToUser(int $userId, string $title, string $body, array $data = []): bool
    {
        if ($this->publicKey === '' || $this->privateKey === '') {
            Log::warning('PushService: کلیدهای VAPID تنظیم نشده، ارسال پوش نادیده گرفته شد.', ['user_id' => $userId]);

            return true;
        }

        // ── گاردِ محیطِ غیرپروداکشن ──────────────────────────────────────────
        // اشتراکِ واقعیِ دستگاهِ کاربران در دیتابیسِ توسعه هم هست؛ بدون این گارد
        // یک `queue:work` روی صفِ انباشته به دستگاهِ مشتریِ واقعی نوتیف می‌فرستد.
        if (! app()->environment('production') && ! $this->allowedInNonProduction($userId)) {
            Log::info('PushService: پوش در محیط غیرپروداکشن ارسال نشد (خارج از allowlist).', [
                'user_id' => $userId,
                'env' => app()->environment(),
                'title' => $title,
            ]);

            return true;
        }

        // فقط ردیف‌هایی که کلیدِ رمزگذاری دارند قابلِ ارسال‌اند. ردیفِ نیتیوِ
        // آینده (بدون کلید) اینجا کنار گذاشته می‌شود، نه اینکه خطا بدهد.
        $subscriptions = DeviceToken::where(DeviceToken::USER_ID, $userId)
            ->whereNotNull(DeviceToken::PUBLIC_KEY)
            ->whereNotNull(DeviceToken::AUTH_KEY)
            ->get();

        if ($subscriptions->isEmpty()) {
            return true;
        }

        // سرویس‌های پوش payloadِ رمزشده را حدودِ ۴ کیلوبایت سقف می‌زنند و
        // رمزگذاری خودش سربار دارد. یک متنِ بلند (مثلاً بدنهٔ یک اعلانِ
        // مدیریتی) بدونِ این برش، **بی‌صدا** رد می‌شود — و چون خطایش
        // «منقضی» نیست، جاب سه بار هم تلاشِ مجدد می‌کند. ضمناً نوتیفیکیشن
        // روی صفحه بیش از چند خط نشان داده نمی‌شود، پس چیزی از دست نمی‌رود.
        $payload = json_encode([
            'title' => mb_substr($title, 0, self::TITLE_MAX),
            'body' => mb_substr($body, 0, self::BODY_MAX),
            'data' => $data,
        ], JSON_UNESCAPED_UNICODE);

        try {
            $webPush = new WebPush(['VAPID' => [
                'subject' => $this->subject,
                'publicKey' => $this->publicKey,
                'privateKey' => $this->privateKey,
            ]]);

            foreach ($subscriptions as $subscription) {
                $webPush->queueNotification(
                    Subscription::create([
                        'endpoint' => $subscription->{DeviceToken::ENDPOINT},
                        'publicKey' => $subscription->{DeviceToken::PUBLIC_KEY},
                        'authToken' => $subscription->{DeviceToken::AUTH_KEY},
                    ]),
                    $payload,
                );
            }

            $sent = 0;
            $failed = 0;

            foreach ($webPush->flush() as $report) {
                if ($report->isSuccess()) {
                    $sent++;

                    continue;
                }

                // ۴۰۴/۴۱۰ یعنی کاربر اجازه را پس گرفته یا مرورگر را پاک کرده.
                // این ردیف دیگر هرگز کار نمی‌کند؛ نگه‌داشتنش یعنی هر نوتیفِ
                // بعدی یک درخواستِ محکوم‌به‌شکستِ اضافه. حذفش تنها راهِ
                // پاک‌سازیِ جدول است — هیچ‌کس دستی خبر نمی‌دهد.
                if ($report->isSubscriptionExpired()) {
                    DeviceToken::where(DeviceToken::ENDPOINT, $report->getEndpoint())->delete();

                    continue;
                }

                // شکستِ واقعی (۵xx، شبکه).
                Log::error('Push delivery failed', [
                    'user_id' => $userId,
                    'reason' => $report->getReason(),
                ]);
                $failed++;
            }

            // ⚠️ تلاشِ مجدد **فقط** وقتی که هیچ‌کدام نرفته باشد.
            //
            // جاب واحدِ تلاشِ مجددش «کاربر» است نه «دستگاه»: یک retry همهٔ
            // اشتراک‌ها را دوباره می‌فرستد. پس اگر از سه دستگاه یکی خطا بدهد
            // و ما false برگردانیم، آن دو دستگاهِ موفق نوتیفیکیشن را **دو
            // بار** می‌گیرند — همان مشکلی که برای پیامک با پنجرهٔ dedupe حل
            // شد. نوتیفِ درون‌برنامه‌ای در هر حال ثبت شده و منبعِ حقیقت است؛
            // پوش فقط تلنگر است، پس نرسیدن به یک دستگاه بهتر از تکرار روی
            // بقیه است. قطعیِ کامل (هیچ ارسالِ موفقی) هنوز retry می‌شود.
            return $sent > 0 || $failed === 0;
        } catch (Throwable $e) {
            // کلیدِ خصوصی هرگز در پیام خطا لاگ نمی‌شود.
            Log::error('Push send error', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    private function allowedInNonProduction(int $userId): bool
    {
        /** @var array<int, int> $allowlist */
        $allowlist = (array) config('services.push.dev_allowlist', []);

        return in_array($userId, $allowlist, true);
    }
}
