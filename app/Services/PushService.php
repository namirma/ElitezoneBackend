<?php

namespace App\Services;

use App\Models\DeviceToken;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;
use Throwable;

/**
 * ارسالِ نوتیفیکیشنِ پوش، روی استانداردِ Web Push.
 *
 * دو ورودی دارد و هر دو از یک مسیرِ ارسال (`deliver`) رد می‌شوند:
 *   - `sendToUser`  — یک کاربر (قلابِ `UserNotification::created`)
 *   - `sendToUsers` — یک تکه‌ی گروهی (`SendBulkPushJob`): یک کوئری، یک
 *     `WebPush`، یک `flush` — نه N بار `sendToUser`
 *
 * سه حالتِ «ارسال نشد ولی خطا نیست» عمداً `true` برمی‌گردانند تا جابِ صف
 * retry/fail نشود (دقیقاً مثل `SmsService::send`):
 *   ۱. کلیدهای VAPID تنظیم نشده‌اند
 *   ۲. محیط غیرپروداکشن و گیرنده خارج از allowlist
 *   ۳. گیرنده هیچ اشتراکِ فعالی ندارد (اپ را نصب/اجازه نداده) — حالتِ اکثریت
 *
 * فقط شکستِ واقعیِ تحویل `false` می‌دهد، تا جاب دوباره تلاش کند.
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
     * @param  array<string, mixed>  $data  داده‌ی ساختاریِ همراه (لینکِ مقصد و…)
     */
    public function sendToUser(int $userId, string $title, string $body, array $data = []): bool
    {
        if (! $this->configured()) {
            Log::warning('PushService: کلیدهای VAPID تنظیم نشده، ارسال پوش نادیده گرفته شد.', ['user_id' => $userId]);

            return true;
        }

        // ── گاردِ محیطِ غیرپروداکشن ──────────────────────────────────────────
        // اشتراکِ واقعیِ دستگاهِ کاربران در دیتابیسِ توسعه هم هست؛ بدون این گارد
        // یک `queue:work` روی صفِ انباشته به دستگاهِ مشتریِ واقعی نوتیف می‌فرستد.
        if (! app()->environment('production') && ! in_array($userId, $this->allowlist(), true)) {
            Log::info('PushService: پوش در محیط غیرپروداکشن ارسال نشد (خارج از allowlist).', [
                'user_id' => $userId,
                'env' => app()->environment(),
                'title' => $title,
            ]);

            return true;
        }

        return $this->deliver($this->subscriptionsFor([$userId]), $title, $body, $data, ['user_id' => $userId]);
    }

    /**
     * ارسالِ یک پیام به گروهی از کاربران در یک تکه.
     *
     * ⚠️ همان گاردِ محیطِ `sendToUser`، ولی **فیلترکننده** نه همه‌یاهیچ: بیرونِ
     * پروداکشن فقط اعضای allowlist از تکه جدا می‌شوند. اگر همه‌یاهیچ بود، یک
     * ارسالِ همگانی روی لپ‌تاپ یا به همه‌ی مشتری‌های واقعی می‌رفت یا به هیچ‌کس —
     * و اولی دقیقاً همان حادثه‌ی پیامکی است که این گارد برای جلوگیری از آن است.
     *
     * @param  list<int>  $userIds
     * @param  array<string, mixed>  $data
     */
    public function sendToUsers(array $userIds, string $title, string $body, array $data = []): bool
    {
        if ($userIds === []) {
            return true;
        }

        if (! $this->configured()) {
            Log::warning('PushService: کلیدهای VAPID تنظیم نشده، ارسال گروهیِ پوش نادیده گرفته شد.', ['users' => count($userIds)]);

            return true;
        }

        if (! app()->environment('production')) {
            $allowed = array_values(array_intersect($userIds, $this->allowlist()));

            if (count($allowed) !== count($userIds)) {
                Log::info('PushService: بخشی از ارسالِ گروهی در محیط غیرپروداکشن کنار گذاشته شد (خارج از allowlist).', [
                    'requested' => count($userIds),
                    'allowed' => count($allowed),
                    'env' => app()->environment(),
                ]);
            }

            $userIds = $allowed;

            if ($userIds === []) {
                return true;
            }
        }

        return $this->deliver($this->subscriptionsFor($userIds), $title, $body, $data, ['users' => count($userIds)]);
    }

    private function configured(): bool
    {
        return $this->publicKey !== '' && $this->privateKey !== '';
    }

    /**
     * فقط ردیف‌هایی که کلیدِ رمزگذاری دارند قابلِ ارسال‌اند. ردیفِ نیتیوِ
     * آینده (بدون کلید) اینجا کنار گذاشته می‌شود، نه اینکه خطا بدهد.
     *
     * @param  list<int>  $userIds
     * @return Collection<int, DeviceToken>
     */
    private function subscriptionsFor(array $userIds): Collection
    {
        return DeviceToken::whereIn(DeviceToken::USER_ID, $userIds)
            ->whereNotNull(DeviceToken::PUBLIC_KEY)
            ->whereNotNull(DeviceToken::AUTH_KEY)
            ->get();
    }

    /**
     * @param  Collection<int, DeviceToken>  $subscriptions
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $context  فقط برای لاگ
     */
    private function deliver(Collection $subscriptions, string $title, string $body, array $data, array $context): bool
    {
        if ($subscriptions->isEmpty()) {
            return true;
        }

        // سرویس‌های پوش payloadِ رمزشده را حدودِ ۴ کیلوبایت سقف می‌زنند و
        // رمزگذاری خودش سربار دارد. یک متنِ بلند (مثلاً بدنه‌ی یک اعلانِ
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
                // بعدی یک درخواستِ محکوم‌به‌شکستِ اضافه.
                if ($report->isSubscriptionExpired()) {
                    DeviceToken::where(DeviceToken::ENDPOINT, $report->getEndpoint())->delete();

                    continue;
                }

                // شکستِ واقعی (۵xx، شبکه).
                Log::error('Push delivery failed', $context + ['reason' => $report->getReason()]);
                $failed++;
            }

            // ⚠️ تلاشِ مجدد **فقط** وقتی که هیچ‌کدام نرفته باشد.
            //
            // واحدِ تلاشِ مجددِ جاب «کاربر» یا «تکه» است نه «دستگاه»: یک retry
            // همه‌ی اشتراک‌ها را دوباره می‌فرستد. پس اگر از سه دستگاه یکی خطا بدهد
            // و ما false برگردانیم، آن دو دستگاهِ موفق نوتیفیکیشن را **دو بار**
            // می‌گیرند — همان مشکلی که برای پیامک با پنجره‌ی dedupe حل شد. در
            // ارسالِ گروهی این اثر چند صد برابر است. نوتیفِ درون‌برنامه‌ای در هر
            // حال ثبت شده و منبعِ حقیقت است؛ پوش فقط تلنگر است.
            return $sent > 0 || $failed === 0;
        } catch (Throwable $e) {
            // کلیدِ خصوصی هرگز در پیام خطا لاگ نمی‌شود.
            Log::error('Push send error', $context + ['error' => $e->getMessage()]);

            return false;
        }
    }

    /** @return list<int> */
    private function allowlist(): array
    {
        return array_values(array_map('intval', (array) config('services.push.dev_allowlist', [])));
    }
}
