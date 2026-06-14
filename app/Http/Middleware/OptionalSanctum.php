<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Bearer token اختیاری — اگر وجود داشت احراز هویت می‌شود، در غیر این‌صورت
 * درخواست به‌عنوان مهمان ادامه می‌یابد.
 *
 * تفاوت با auth:sanctum: نبود token مجاز است (guest).
 * اگر token ارسال شده اما نامعتبر/منقضی باشد → 401 برگردانده می‌شود.
 *
 * نکته: shouldUse('sanctum') ضروری است تا Auth::id() و Auth::user() در کنترلرها
 * و سرویس‌های داخلی (مثل CartResolver) کاربر احراز هویت‌شده را برگردانند.
 * بدون آن، authenticate() فقط روی sanctum guard ست می‌کند نه default guard.
 */
class OptionalSanctum
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->bearerToken()) {
            app('auth')->guard('sanctum')->authenticate();
            app('auth')->shouldUse('sanctum');
        }

        return $next($request);
    }
}
