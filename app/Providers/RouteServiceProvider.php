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
            return Limit::perMinute(2)->by($request->ip());
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
            Route::middleware(['api', 'cors'])
                ->prefix('api')
                ->group(base_path('routes/api.php'));

            Route::middleware('web')
                ->group(base_path('routes/web.php'));
        });
    }
}
