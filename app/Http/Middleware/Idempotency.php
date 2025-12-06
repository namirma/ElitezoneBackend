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

        if (empty($key)) {
            return $next($request);
        }

        $cacheKey = "idempotency_response:{$key}";
        $lockKey = "idempotency_lock:{$key}";

        if (Cache::has($cacheKey)) {
            $cachedResponse = Cache::get($cacheKey);

            return response()->json(
                $cachedResponse['content'],
                $cachedResponse['status'],
                array_merge($cachedResponse['headers'], ['X-Idempotency-Hit' => 'true'])
            );
        }

        $lock = Cache::lock($lockKey, 10);

        if (!$lock->get()) {
            return response()->json([
                'status' => false,
                'message' => '.درخواست تکراری در حال پردازش است. لطفاً صبر کنید',
                'code' => 'DUPLICATE_REQUEST_PROCESSING'
            ], 409);
        }

        try {
            $response = $next($request);

            if ($response->isSuccessful()) {
                $responseData = [
                    'content' => json_decode($response->getContent(), true),
                    'status' => $response->getStatusCode(),
                    'headers' => $response->headers->all(),
                ];

                Cache::put($cacheKey, $responseData, now()->addHours(self::CACHE_TTL_HOURS));
            }

            return $response;

        } finally {
            $lock->release();
        }
    }
}
