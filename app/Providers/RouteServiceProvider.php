<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;

class RouteServiceProvider extends ServiceProvider
{
    /**
     * The path to your application's "home" route.
     *
     * Typically, users are redirected here after authentication.
     *
     * @var string
     */
    public const HOME = '/home';

    /**
     * Define your route model bindings, pattern filters, and other route configuration.
     */
    public function boot(): void
    {
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });

        RateLimiter::for('sms', function (Request $request) {
            return Limit::perMinute(2)->by($request->ip())->response(function (Request $request, array $headers) {
                $retryAfter = (int) ($headers['Retry-After'] ?? 60);
                $minutes = (int) ceil($retryAfter / 60);
                $timeLabel = $minutes > 1 ? "{$minutes} دقیقه" : '۱ دقیقه';

                return error(
                    "تلاش بیش از حد مجاز است. لطفاً {$timeLabel} دیگر دوباره امتحان کنید.",
                    ['retry_after_seconds' => $retryAfter],
                    429
                );
            });
        });

        /**
         * محدودیت برای تأیید کد OTP — جلوگیری از brute-force روی کد ۶ رقمی.
         *
         * کلید throttle: IP + شماره موبایل (username)
         *   - هر ترکیب IP+موبایل حداکثر ۵ تلاش در دقیقه
         *   - مهاجم حتی با چرخش IP نمی‌تواند به یک حساب حمله کند
         *   - کاربر عادی کد را یک بار و درست وارد می‌کند؛ ۵ بار فرصت برای اشتباه تایپی کافی است
         */
        RateLimiter::for('otp-verify', function (Request $request) {
            // تفکیک‌کننده: برای مسیرهای مهمان (ورود/بازیابی رمز) شمارهٔ موبایلِ ورودی،
            // و برای مسیرهای احرازشده (تأیید تغییر موبایل، تأیید رمز) شناسهٔ کاربر.
            // قبلاً همیشه username بود؛ روی مسیرهای احرازشده که username ندارند کلید
            // به «IP + رشتهٔ خالی» می‌رسید و همهٔ کاربرانِ پشتِ یک NAT یک سطل مشترک
            // می‌گرفتند — هم نشتِ throttle بین کاربران، هم ضعیف‌ترشدنِ گاردِ brute-force.
            $discriminator = $request->user()?->id
                ?? (string) $request->input('username', '');

            $throttleKey = 'otp:'.$request->ip().':'.$discriminator;

            return Limit::perMinute(5)->by($throttleKey)->response(function () {
                return error(__('auth.too_many_otp_attempts'), [], 429);
            });
        });

        /**
         * نرخ‌محدودکننده هوشمند برای عملیات مالی (پرداخت، کیف پول)
         *
         * نقش‌بندی:
         *  - ادمین       : بدون محدودیت (ops + testing)
         *  - تأمین‌کننده تأیید شده : ۳۰ درخواست/دقیقه — در پنجره‌های بارگیری نیاز به burst دارد
         *  - کاربر عادی  : ۱۰ درخواست/دقیقه
         *  - ناشناس       : ۵ درخواست/دقیقه (safety net — مسیرهای مالی auth دارند)
         *
         * کلید throttle بر اساس user_id است (نه IP) تا کاربر از VPN/Tor
         * محدودیت را دور نزند و برای حساب‌های مشترک ایمن باشد.
         */
        RateLimiter::for('financial', function (Request $request) {
            $user = $request->user();

            if (! $user) {
                return Limit::perMinute(5)->by($request->ip())->response(function () {
                    return error(__('global.too_many_requests'), [], 429);
                });
            }

            if ($user->hasRole('admin')) {
                return Limit::none();
            }

            // تأمین‌کننده‌ای که پروفایل تأییدشده دارد — burst بالاتر
            if ($user->hasRole('supplier') && $user->supplier !== null) {
                return Limit::perMinute(30)->by($user->id)->response(function () {
                    return error(__('global.too_many_requests'), [], 429);
                });
            }

            // سایر کاربران احراز هویت شده
            return Limit::perMinute(10)->by($user->id)->response(function () {
                return error(__('global.too_many_requests'), [], 429);
            });
        });

        $this->routes(function () {
            // CORS is handled ONLY by Laravel's global HandleCors (Kernel::$middleware),
            // which reads config/cors.php. A second, hand-rolled `cors` middleware used
            // to be stacked here with its own HARDCODED allowlist; because it wrote
            // Access-Control-Allow-Origin unconditionally on the way out, it overrode the
            // config and re-allowed http://localhost:* with credentials on production —
            // exactly what config/cors.php gates behind APP_ENV=local. One allowlist, one
            // source of truth: config/cors.php. Never re-add a route-level CORS middleware.
            Route::middleware('api')
                ->prefix('api')
                ->group(base_path('routes/api.php'));

            Route::middleware('web')
                ->group(base_path('routes/web.php'));
        });
    }
}
