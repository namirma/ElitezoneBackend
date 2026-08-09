<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Models\UserType;
use Carbon\Carbon;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * گیتِ «نقش انتخاب شده؟» روی checkout (طبق فلوچارت پروسه خرید).
 *
 * این میدل‌ور تا پیش از این کدِ مرده بود: گاردِ ورودی `type_id !== null` را
 * چک می‌کرد، اما `AuthController::register` هنگام ساختِ کاربر همیشه یک
 * `type_id` پیش‌فرض با اسلاگ `customer` می‌نشاند — پس شرط همیشه برقرار بود و
 * هیچ‌وقت به منطق پنجرهٔ زمانی نمی‌رسید.
 *
 * حالا «انتخابِ نقش» درست تعریف شده: کاربر وقتی نقش دارد که یکی از نقش‌های
 * واقعیِ Laratrust را داشته باشد، یا `type_id`ش یکی از سه نوعِ آنبردینگ
 * (elite_yar / employer / supplier) باشد — دقیقاً همان چیزی که
 * `SetUserTypeAction` می‌نویسد. `customer` صرفاً یک placeholder ثبت‌نام است و
 * «انتخابِ نقش» به حساب نمی‌آید.
 *
 * گیت به‌صورت opt-in است (`auth.role_selection_gate_enabled`, پیش‌فرض false):
 * روشن‌کردنش checkout را برای کاربرانِ بی‌نقش ۴۰۳ می‌کند و فرانت باید
 * کدهای ROLE_NOT_SELECTED / REGISTRATION_WINDOW_EXPIRED را هندل کند.
 */
class EnsureRoleIsSelected
{
    /** UserType slugs that count as a completed role selection. */
    private const ONBOARDING_TYPE_SLUGS = ['elite_yar', 'employer', 'supplier'];

    /** Laratrust roles that count as a completed role selection. */
    private const ONBOARDING_ROLES = ['engineer', 'contractor', 'employer', 'supplier'];

    public function handle(Request $request, Closure $next): Response
    {
        if (! config('auth.role_selection_gate_enabled', false)) {
            return $next($request);
        }

        /** @var User|null $user */
        $user = $request->user();

        if (! $user || $this->hasSelectedRole($user)) {
            return $next($request);
        }

        // =========================================================================
        //  The registration-window logic (طبق فلوچارت)
        // =========================================================================

        $windowHours = (int) config('auth.role_selection_window_hours', 2);

        $hoursPassed = Carbon::parse($user->{User::CREATED_AT})->diffInHours(now(), absolute: true);
        $isWindowOpen = $hoursPassed < $windowHours;

        $metaData = [
            'role_selected' => false,
            // Carbon 3 (روی برنچ لاراول ۱۳) از diffInHours عدد اعشاری برمی‌گرداند؛
            // برای اینکه قراردادِ فرانت بین دو برنچ یکسان بماند به int گرد می‌شود.
            'hours_passed' => (int) floor($hoursPassed),
            'window_hours' => $windowHours,
            'registration_window_open' => $isWindowOpen,
        ];

        if (! $isWindowOpen) {
            return response()->json([
                'status' => false,
                'message' => __('auth.role_selection.timeout'),
                'code' => 'REGISTRATION_WINDOW_EXPIRED',
                'action' => 'show_limited_attachments',
                'meta' => $metaData,
            ], 403);
        }

        return response()->json([
            'status' => false,
            'message' => __('auth.role_selection.required'),
            'code' => 'ROLE_NOT_SELECTED',
            'action' => 'force_role_selection',
            'meta' => $metaData,
        ], 403);
    }

    /**
     * نقش واقعی دارد؟ (نه placeholderِ ثبت‌نام)
     *
     * بررسیِ نقشِ Laratrust اول می‌آید تا هیچ کاربری که آنبردینگ را تمام کرده
     * — حتی اگر type_id به هر دلیلی عقب مانده باشد — پشتِ گیت گیر نکند.
     */
    private function hasSelectedRole(User $user): bool
    {
        if ($user->hasRole(self::ONBOARDING_ROLES)) {
            return true;
        }

        // `users.type_id` NOT NULL است (create_users_table، بدون nullable) — پس
        // گاردِ قدیمیِ `type_id !== null` دوبار مرده بود: هم register همیشه
        // مقدارش را می‌گذاشت و هم ستون اصلاً null نمی‌پذیرد. تنها پرسشِ معنادار
        // این است که type_id به کدام slug اشاره می‌کند.
        return UserType::query()
            ->whereKey($user->{User::TYPE_ID})
            ->whereIn(UserType::SLUG, self::ONBOARDING_TYPE_SLUGS)
            ->exists();
    }
}
