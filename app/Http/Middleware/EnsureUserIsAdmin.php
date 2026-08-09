<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * بررسی نقش ادمین برای مسیرهای پنل ادمین
 *
 * چرا این middleware جدا از Laratrust `role:admin` است:
 *   Laratrust از Auth::guard(config('auth.defaults.guard')) استفاده می‌کند
 *   که guard پیش‌فرض `web` است. اما کاربران API از طریق Sanctum احراز هویت
 *   می‌شوند و $request->user() را پر می‌کنند — نه guard وب را.
 *   نتیجه: `role:admin` همیشه 403 برمی‌گرداند حتی برای کاربران ادمین واقعی.
 *
 * راه‌حل: مانند EnsureUserIsEliteYAR از $request->user() استفاده می‌کنیم
 * که پس از auth:sanctum همیشه کاربر احراز هویت شده را برمی‌گرداند.
 */
class EnsureUserIsAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return error(__('auth.unauthenticated'), [], 401);
        }

        if (! $user->hasRole('admin')) {
            return error(__('auth.forbidden'), [], 403);
        }

        return $next($request);
    }
}
