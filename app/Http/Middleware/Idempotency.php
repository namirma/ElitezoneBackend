<?php

namespace App\Http\Middleware;

use Closure;
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

        // اگر کلاینت هدرِ Idempotency-Key نفرستد، به‌جای رهاکردنِ درخواست (که دابل‌کلیک/
        // retryِ کلاینت را روی مسیر مالی بی‌محافظت می‌گذاشت) یک «اثرانگشتِ» کوتاه‌عمر از
        // خودِ درخواست می‌سازیم: (متد + مسیر + هشِ بدنه). این درخواست را الزامی نمی‌کند
        // (هرگز ۴۲۲ نمی‌دهیم و فرانت نمی‌شکند) اما ارسال‌های تکراریِ لحظه‌ایِ یکسان را
        // در یک پنجرهٔ کوتاه حذف می‌کند. پنجره کوتاه است تا دو درخواستِ واقعاً مجزا با
        // فاصله بلاک نشوند.
        if (empty($key)) {
            $key = 'fp:'.sha1(implode('|', [
                $request->method(),
                $request->path(),
                (string) $request->getContent(),
            ]));
            $isFingerprint = true;
        }

        // مهمان‌ها با IP از هم جدا می‌شوند — کلید مشترک 'guest' باعث می‌شد دو
        // کاربر ناشناس با Idempotency-Key یکسان، پاسخ کش‌شده‌ی یکدیگر را بگیرند.
        $userId = optional($request->user())->id ?? 'guest:'.$request->ip();
        $cacheKey = "idempotency_response:{$userId}:{$key}";
        $lockKey = "idempotency_lock:{$userId}:{$key}";

        if (Cache::has($cacheKey)) {
            $cachedResponse = Cache::get($cacheKey);

            return response()->json(
                $cachedResponse['content'],
                $cachedResponse['status'],
                array_merge($cachedResponse['headers'], ['X-Idempotency-Hit' => 'true'])
            );
        }

        $lock = Cache::lock($lockKey, 10);

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
                        ];

                        // کلیدِ صریحِ کلاینت ۲۴ ساعت نگه داشته می‌شود (idempotencyِ کامل)،
                        // اما کلیدِ اثرانگشتیِ خودکار فقط چند ثانیه (صرفاً ضدِ دابل‌سابمیت).
                        $ttl = $isFingerprint
                            ? now()->addSeconds((int) config('idempotency.fingerprint_window_seconds', 10))
                            : now()->addHours(self::CACHE_TTL_HOURS);

                        Cache::put($cacheKey, $responseData, $ttl);
                    }
                }
            }

            return $response;

        } finally {
            $lock->release();
        }
    }
}
