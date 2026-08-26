<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * سقفِ اختیارِ ادمین — لایه‌ی دوم، **کنارِ** `admin.permission` نه به‌جای آن.
 *
 * این دو پرسشِ متفاوت را جواب می‌دهند و هر دو باید پاس شوند:
 *   admin.permission → «به کدام بخش دسترسی دارد»
 *   admin.level      → «تا کجا حق دارد»
 *
 * بدونِ لایه‌ی دوم، هر ادمینی که مجوزِ manage-users داشت می‌توانست ادمین بسازد،
 * به خودش هر مجوزی بدهد و عملاً به اختیارِ مالکِ برند برسد؛ یعنی هیچ مرزی برای
 * ارتقای خودخواسته وجود نداشت.
 *
 * استفاده: `middleware('admin.level:1')` یعنی «فقط سطح ۱ و بالاتر» — و چون ۱
 * بالاترین است، یعنی فقط مدیرِ ارشد.
 *
 * fail-closed مثلِ خواهرش: نبودِ سطح = ۴۰۳.
 */
class EnsureAdminHasLevel
{
    public function handle(Request $request, Closure $next, string $level): Response
    {
        $user = $request->user();
        $required = (int) $level;

        if (! $user instanceof User || ! $user->hasAdminLevel($required)) {
            return response()->json([
                'status' => false,
                'message' => __('admin.levels.forbidden'),
                'errors' => [],
                // فرانت باید بتواند «سطحت کافی نیست» را از «مجوزت را نداری» جدا
                // کند: اولی با تغییرِ مجوز حل نمی‌شود و پیامِ متفاوتی می‌خواهد.
                'code' => 'ADMIN_LEVEL_TOO_LOW',
                'meta' => ['required_level' => $required],
            ], 403);
        }

        return $next($request);
    }
}
