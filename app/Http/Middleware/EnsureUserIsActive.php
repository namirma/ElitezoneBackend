<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * بلاک‌کردن کاربران غیرفعال‌شده (users.is_active = false) در کل API.
 *
 * بدون این middleware، غیرفعال‌کردن کاربر توسط ادمین
 * (AdminUserController::toggleStatus) هیچ اثری نداشت: توکن‌های Sanctum موجود
 * همچنان کار می‌کردند. در گروه middleware «api» ثبت شده تا هر درخواستِ دارای
 * توکن — حتی روت‌های optional.sanctum — پوشش داده شود.
 *
 * مقایسه با `=== false` عمدی است: مقدار NULL (ردیف‌های قدیمی بدون backfill)
 * باید «فعال» تلقی شود، نه اینکه کاربر قفل شود.
 */
class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user('sanctum');

        if ($user && $user->{User::IS_ACTIVE} === false) {
            return error(__('auth.account_disabled'), [], 403);
        }

        return $next($request);
    }
}
