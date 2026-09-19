<?php

namespace App\Providers;

use App\Models\Order;
use App\Models\ProductPrice;
use App\Models\Ticket;
use App\Models\User;
use App\Observers\OrderObserver;
use App\Observers\ProductPriceObserver;
use App\Observers\TicketObserver;
use Dedoc\Scramble\Scramble;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\Server;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

use function in_array;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // env() is intentionally NOT used here — use config() so that
        // `php artisan config:cache` is respected in production.
        if (! config('app.debug')) {
            URL::forceScheme('https');
        }

        if (config('app.debug')) {
            URL::forceScheme('http');
        }
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureRateLimiters();

        // پراکسی‌های مورد اعتماد (Cloudflare) — این‌جا و نه در `bootstrap/app.php`، چون
        // آن closure پیش از لودِ config اجرا می‌شود. boot پیش از pipelineِ هر درخواست
        // اجرا می‌شود و با config:cache هم درست کار می‌کند. config/security.php را ببینید.
        TrustProxies::at(config('security.trusted_proxies'));

        Gate::define('viewApiDocs', function (?User $user = null) {
            if (app()->environment('local')) {
                return true;
            }

            // Authorised emails are set via APP_API_DOCS_EMAILS= in .env
            // (comma-separated). Managed in config/app.php → api_docs_emails.
            $allowed = (array) config('app.api_docs_emails', []);

            return $user && in_array($user->email, $allowed, strict: true);
        });

        // سرورِ `api.json` نسبی (`/api`)، نه ساخته‌شده از `APP_URL`.
        //
        // Scramble نشانیِ سرور را با `url()` از APP_URL و طرحِ اجباریِ بالا (http در
        // debug، https بیرونش) می‌ساخت؛ پس خروجیِ هر ماشین فقط در همین یک مقدار با
        // دیگری فرق داشت — و گیتِ CI که `api.json` را بایت‌به‌بایت مقایسه می‌کند با
        // `.env.example` (`https://localhost:8000`) همیشه قرمز می‌شد. OpenAPI نشانیِ
        // نسبی را مجاز می‌داند و مستندات روی هر دامنه‌ای (لوکال/استیجینگ/تولید) درست
        // به همان میزبان اشاره می‌کند؛ دامنه‌ی تولید در ریپو ثبت نشده که حدسش بزنیم.
        Scramble::configure()->withDocumentTransformers(function (OpenApi $openApi) {
            $openApi->servers = [new Server('/api')];
        });

        ProductPrice::observe(ProductPriceObserver::class);
        Order::observe(OrderObserver::class);
        Ticket::observe(TicketObserver::class);

        if (app()->isProduction() && config('queue.default') === 'sync') {
            Log::critical('QUEUE_CONNECTION=sync in production — queued listeners run inside the HTTP request and block responses.');
        }
        if (app()->isProduction() && config('cache.default') === 'file') {
            Log::critical('CACHE_DRIVER=file in production — OTP lockout and idempotency keys are not shared across servers.');
        }
    }

    /**
     * Rate limiters — سابقاً در RouteServiceProvider تعریف می‌شدند.
     * در لاراول ۱۳، اینجا در AppServiceProvider قرار می‌گیرند.
     */
    private function configureRateLimiters(): void
    {
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });

        RateLimiter::for('sms', function (Request $request) {
            $retryAfterFn = function (Request $request, array $headers) {
                $retryAfter = (int) ($headers['Retry-After'] ?? 60);
                $minutes = (int) ceil($retryAfter / 60);
                $timeLabel = $minutes > 1 ? "{$minutes} دقیقه" : '۱ دقیقه';

                return error(
                    "تلاش بیش از حد مجاز است. لطفاً {$timeLabel} دیگر دوباره امتحان کنید.",
                    ['retry_after_seconds' => $retryAfter],
                    429
                );
            };

            return Limit::perMinute(2)->by($request->ip())->response($retryAfterFn);
        });

        /**
         * محدودیت برای تأیید کد OTP — جلوگیری از brute-force روی کد ۶ رقمی.
         *
         * کلید throttle: IP + شماره موبایل (username)
         *   - هر ترکیب IP+موبایل حداکثر ۵ تلاش در دقیقه
         */
        RateLimiter::for('otp-verify', function (Request $request) {
            // تفکیک‌کننده: برای مسیرهای مهمان (ورود/بازیابی رمز) شماره‌ی موبایلِ ورودی،
            // و برای مسیرهای احرازشده (تأیید تغییر موبایل، تأیید رمز) شناسه‌ی کاربر.
            // قبلاً همیشه username بود؛ روی مسیرهای احرازشده که username ندارند کلید
            // به «IP + رشته‌ی خالی» می‌رسید و همه‌ی کاربرانِ پشتِ یک NAT یک سطل مشترک
            // می‌گرفتند — هم نشتِ throttle بین کاربران، هم ضعیف‌ترشدنِ گاردِ brute-force.
            // مرحله‌ی دومِ ورودِ دومرحله‌ای (`/login/2fa`) نه کاربر دارد نه username؛
            // کلیدش خودِ چالش است تا کوبیدنِ یک چالش، بقیه‌ی پشتِ همان NAT را قفل نکند.
            $user = $request->user();
            $discriminator = $user !== null
                ? (string) $user->id
                : (string) ($request->input('username') ?: $request->input('challenge', ''));

            $throttleKey = 'otp:'.$request->ip().':'.$discriminator;

            return Limit::perMinute(5)->by($throttleKey)->response(function () {
                return error(__('auth.too_many_otp_attempts'), [], 429);
            });
        });

        /**
         * نرخ‌محدودکننده هوشمند برای عملیات مالی.
         * - ادمین: بدون محدودیت
         * - تأمین‌کننده تأیید‌شده: ۳۰ درخواست/دقیقه
         * - کاربر عادی: ۱۰ درخواست/دقیقه
         * - ناشناس: ۵ درخواست/دقیقه
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

            if ($user->hasRole('supplier') && $user->supplier !== null) {
                return Limit::perMinute(30)->by($user->id)->response(function () {
                    return error(__('global.too_many_requests'), [], 429);
                });
            }

            return Limit::perMinute(10)->by($user->id)->response(function () {
                return error(__('global.too_many_requests'), [], 429);
            });
        });
    }
}
