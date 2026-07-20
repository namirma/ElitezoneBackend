<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\Response;

/**
 * محدودیت IP روی پنل ادمین — لایه دفاعی اضافه بر auth + role.admin.
 *
 * لیست از config('security.admin_ip_whitelist') می‌آید (env ADMIN_IP_WHITELIST،
 * IP تکی یا CIDR). لیست خالی = غیرفعال (پیش‌فرض) — هیچ رفتاری نمی‌شکند تا
 * صراحتاً روشن شود. تلاش ردشده برای پیگیری امنیتی لاگ می‌شود.
 */
class RestrictAdminByIp
{
    public function handle(Request $request, Closure $next): Response
    {
        $whitelist = config('security.admin_ip_whitelist', []);

        if ($whitelist === []) {
            return $next($request);
        }

        $ip = (string) $request->ip();

        if (! IpUtils::checkIp($ip, $whitelist)) {
            Log::warning('Admin panel access blocked by IP whitelist', [
                'ip' => $ip,
                'path' => $request->path(),
                'user_id' => $request->user()?->id,
            ]);

            return response()->json([
                'status' => false,
                'message' => __('admin.permissions.ip_blocked'),
                'errors' => [],
            ], 403);
        }

        return $next($request);
    }
}
