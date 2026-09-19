<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * سطح‌بندی دسترسی داخل پنل ادمین (بعد از role.admin).
 *
 * مجوزها به صورت user-level به هر ادمین داده می‌شوند (AdminPermissionSeeder همه
 * مجوزها را به ادمین‌های موجود می‌دهد؛ AdminManagerController هنگام ساخت/ویرایش
 * ادمین لیست مجوزها را sync می‌کند). استفاده: middleware('admin.permission:manage-financial')
 *
 * fail-closed: نبودِ مجوز = ۴۰۳. قبلاً «صفر مجوز = دسترسی کامل» بود که دقیقاً
 * وارونه‌ی نیتِ AdminManagerController::update بود — فرستادن permissions=[] برای
 * سلبِ دسترسی، به ادمین دسترسیِ کامل (از جمله manage-financial) می‌داد.
 * سازگاری عقب‌رو حالا یک‌بار و برای همیشه در مایگریشنِ
 * 2026_07_25_100000_backfill_admin_permissions انجام می‌شود.
 */
class EnsureAdminHasPermission
{
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $user = $request->user();

        if (! $user || ! $user->hasPermission($permission)) {
            return $this->forbidden();
        }

        return $next($request);
    }

    private function forbidden(): Response
    {
        return response()->json([
            'status' => false,
            'message' => __('admin.permissions.forbidden'),
            'errors' => [],
        ], 403);
    }
}
