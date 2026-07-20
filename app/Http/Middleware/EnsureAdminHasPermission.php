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
 */
class EnsureAdminHasPermission
{
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $user = $request->user();

        if (! $user) {
            return $this->forbidden();
        }

        // سازگاری عقب‌رو: ادمینی که هیچ مجوز user-level ثبت‌شده‌ای ندارد
        // (seeder اجرا نشده / ادمین قدیمی) = دسترسی کامل قدیمی. به محض
        // تخصیص اولین مجوز، سطح‌بندی برای او فعال می‌شود.
        if ($user->permissions()->count() === 0) {
            return $next($request);
        }

        if (! $user->hasPermission($permission)) {
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
