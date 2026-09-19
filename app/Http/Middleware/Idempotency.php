<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

class Idempotency
{
    /**
     * نام هدر که فرانت‌اند باید بفرستد (یک UUID یونیک)
     */
    const HEADER_KEY = 'Idempotency-Key';

    /**
     * مدت زمان نگهداری پاسخ در کش (مثلاً ۲۴ ساعت)
     */
    const CACHE_TTL_HOURS = 24;

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethodSafe()) {
            return $next($request);
        }

        $key = $request->header(self::HEADER_KEY);
        $isFingerprint = false;

        // اثرانگشتِ خودِ درخواست — برای تشخیصِ «کلیدِ یکسان، درخواستِ متفاوت».
        $requestFingerprint = sha1(implode('|', [
            $request->method(),
            $request->path(),
            (string) $request->getContent(),
        ]));

        // اگر کلاینت هدرِ Idempotency-Key نفرستد، به‌جای رهاکردنِ درخواست (که دابل‌کلیک/
        // retryِ کلاینت را روی مسیر مالی بی‌محافظت می‌گذاشت) یک «اثرانگشتِ» کوتاه‌عمر از
        // خودِ درخواست می‌سازیم: (متد + مسیر + هشِ بدنه). این درخواست را الزامی نمی‌کند
        // (هرگز ۴۲۲ نمی‌دهیم و فرانت نمی‌شکند) اما ارسال‌های تکراریِ لحظه‌ایِ یکسان را
        // در یک پنجره‌ی کوتاه حذف می‌کند. پنجره کوتاه است تا دو درخواستِ واقعاً مجزا با
        // فاصله بلاک نشوند.
        if (empty($key)) {
            $key = 'fp:'.$requestFingerprint;
            $isFingerprint = true;
        }

        // مهمان‌ها با IP از هم جدا می‌شوند — کلید مشترک 'guest' باعث می‌شد دو
        // کاربر ناشناس با Idempotency-Key یکسان، پاسخ کش‌شده‌ی یکدیگر را بگیرند.
        $userId = optional($request->user())->id ?? 'guest:'.$request->ip();

        // کلیدِ کش به «متد + مسیر» هم مقید است. پیش از این فقط (کاربر + کلید) بود، پس
        // اگر کلاینت یک UUID را برای دو درخواستِ مالیِ متفاوت به‌کار می‌برد — مثلاً یک
        // کلید به ازای هر بار لودِ صفحه و سپس هم pay-hold-fee و هم pay-full، یا
        // pay-full روی دو سفارشِ مختلف — پاسخِ اولی برای دومی replay می‌شد: سفارشِ دوم
        // «پرداخت‌شده» جواب می‌گرفت بدون آنکه یک ریال جابه‌جا شود.
        $scope = sha1($request->method().'|'.$request->path());
        $cacheKey = "idempotency_response:{$userId}:{$scope}:{$key}";
        $lockKey = "idempotency_lock:{$userId}:{$scope}:{$key}";

        // storeِ *مشترک بین سرورها* — نه درایورِ پیش‌فرض. با درایورِ `file` هم قفل و هم
        // پاسخِ کش‌شده per-server می‌شدند، پس روی استقرارِ چندسروری دو درخواستِ هم‌زمانِ
        // پرداخت روی دو سرور هر دو قفل را می‌گرفتند و هر دو اجرا می‌شدند — گاردِ مالی
        // عملاً بی‌اثر بود. جزئیات در config/idempotency.php.
        $cache = Cache::store(config('idempotency.store'));

        if ($cache->has($cacheKey)) {
            $cachedResponse = $cache->get($cacheKey);

            // همان کلید روی همان endpoint ولی با بدنه‌ی متفاوت = خطای کلاینت، نه تکرار.
            // برگرداندنِ پاسخِ کش‌شده اینجا یعنی پذیرفتنِ عملیاتی که هرگز اجرا نشده؛
            // پس صریحاً ۴۲۲ می‌دهیم (رفتارِ استانداردِ درگاه‌های پرداخت).
            if (
                ! $isFingerprint
                && isset($cachedResponse['fingerprint'])
                && ! hash_equals((string) $cachedResponse['fingerprint'], $requestFingerprint)
            ) {
                return error(
                    __('global.idempotency_key_reused'),
                    ['code' => 'IDEMPOTENCY_KEY_REUSED'],
                    422
                );
            }

            return response()->json(
                $cachedResponse['content'],
                $cachedResponse['status'],
                array_merge($cachedResponse['headers'], ['X-Idempotency-Hit' => 'true'])
            );
        }

        // قفل از خودِ store گرفته می‌شود، نه از Repository — `lock()` روی قرارداد
        // Repository اعلام نشده و فقط از طریق __call پروکسی می‌شود. این annotation هم
        // شرطِ لازم را صریح می‌کند: storeِ انتخاب‌شده باید LockProvider باشد
        // (database / redis / array همه هستند؛ file نیست — و اصلاً به همین دلیل
        // پیش‌فرض عوض شد).
        /** @var LockProvider $lockProvider */
        $lockProvider = $cache->getStore();
        $lock = $lockProvider->lock($lockKey, 10);

        if (! $lock->get()) {
            return error(__('global.system_busy_try_again'), ['code' => 'DUPLICATE_REQUEST_PROCESSING'], 409);
        }

        try {
            $response = $next($request);

            if ($response->isSuccessful()) {
                $contentType = $response->headers->get('Content-Type', '');

                // فقط پاسخ‌های JSON را کش کن تا بدنه خراب نشود
                if (is_string($contentType) && str_contains($contentType, 'application/json')) {
                    $decoded = json_decode($response->getContent(), true);

                    // اگر JSON نامعتبر بود، کش نکن
                    if (json_last_error() === JSON_ERROR_NONE) {
                        // فقط هدرهای امن کش می‌شوند — کش کردن همه هدرها می‌توانست
                        // Set-Cookie/توکن یک پاسخ را برای درخواست بعدی replay کند.
                        $safeHeaders = array_intersect_key(
                            $response->headers->all(),
                            array_flip(['content-type', 'content-language'])
                        );

                        $responseData = [
                            'content' => $decoded,
                            'status' => $response->getStatusCode(),
                            'headers' => $safeHeaders,
                            // برای تشخیصِ استفاده‌ی مجددِ کلید با بدنه‌ی متفاوت.
                            'fingerprint' => $requestFingerprint,
                        ];

                        // کلیدِ صریحِ کلاینت ۲۴ ساعت نگه داشته می‌شود (idempotencyِ کامل)،
                        // اما کلیدِ اثرانگشتیِ خودکار فقط چند ثانیه (صرفاً ضدِ دابل‌سابمیت).
                        $ttl = $isFingerprint
                            ? now()->addSeconds((int) config('idempotency.fingerprint_window_seconds', 10))
                            : now()->addHours(self::CACHE_TTL_HOURS);

                        $cache->put($cacheKey, $responseData, $ttl);
                    }
                }
            }

            return $response;

        } finally {
            $lock->release();
        }
    }
}
