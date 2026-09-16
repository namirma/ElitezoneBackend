<?php

use App\Exceptions\Handler;
use App\Http\Middleware\AttachActivePriceCategory;
use App\Http\Middleware\Authenticate;
use App\Http\Middleware\CanSeeTicket;
use App\Http\Middleware\EnsureAdminHasLevel;
use App\Http\Middleware\EnsureAdminHasPermission;
use App\Http\Middleware\EnsureRoleIsSelected;
use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\EnsureUserIsAdmin;
use App\Http\Middleware\EnsureUserIsEliteYAR;
use App\Http\Middleware\EnsureUserIsEmployer;
use App\Http\Middleware\EnsureUserIsSupplier;
use App\Http\Middleware\Idempotency;
use App\Http\Middleware\OptionalSanctum;
use App\Http\Middleware\RedirectIfAuthenticated;
use App\Http\Middleware\RestrictAdminByIp;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\TrackDashboardUsage;
use App\Http\Middleware\ValidateSignature;
use Illuminate\Auth\Middleware\AuthenticateWithBasicAuth;
use Illuminate\Auth\Middleware\Authorize;
use Illuminate\Auth\Middleware\EnsureEmailIsVerified;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Foundation\Http\Middleware\HandlePrecognitiveRequests;
use Illuminate\Http\Middleware\SetCacheHeaders;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Session\Middleware\AuthenticateSession;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    /*
    |--------------------------------------------------------------------------
    | Event discovery — OFF
    |--------------------------------------------------------------------------
    | `Application::configure()` خودش `withEvents()` را با discovery روشن صدا
    | می‌زند و `Foundation\Support\Providers\EventServiceProvider` را ثبت می‌کند که
    | `app/Listeners` را اسکن می‌کند. `App\Providers\EventServiceProvider` هم همان
    | listenerها را از `$listen` ثبت می‌کند → هر listener دو بار (`event:list` هر
    | کدام را دو بار نشان می‌داد) و یک `OrderPaid` هر پیامک را دو بار می‌فرستاد.
    |
    | منبعِ واحد: `$listen`. discovery را برنگردانید مگر آن نقشه را حذف کنید.
    | پین: `Security/EventListenersRegisteredOnceTest`.
    */
    ->withEvents(discover: false)
    ->withMiddleware(function (Middleware $middleware) {

        /*
        |----------------------------------------------------------------------
        | TrustHosts
        |----------------------------------------------------------------------
        | ⚠️ برخلافِ TrustProxies و HandleCors، این یکی در استکِ سراسریِ پیش‌فرضِ
        | لاراول ۱۱ به بعد **نیست** و فقط با همین فراخوانی اضافه می‌شود
        | (`Configuration\Middleware::getGlobalMiddleware()` را ببینید).
        |
        | این فراخوانی لازم است چون کلاسِ `app/Http/Middleware/TrustHosts.php` هست
        | ولی بدونِ آن هیچ‌چیز صدایش نمی‌زند (روی L11+ دیگر `Http/Kernel.php` خوانده
        | نمی‌شود). بدونِ این خط، اعتبارسنجیِ هدرِ Host بی‌صدا غایب است — همان چیزی
        | که لینکِ بازیابی رمز را در برابرِ host-header poisoning نگه می‌دارد.
        |
        | ⚠️ **تصحیحِ تاریخی (۲۰۲۶-۰۹-۰۸).** این‌جا نوشته بود «روی شاخهٔ L10 این
        | میدل‌ور فعال بود و در پورت جا ماند». اندازه‌گیری خلافش را می‌گوید:
        |
        |   origin/redesign/order-flow-l10 : app/Http/Kernel.php:55
        |       // \App\Http\Middleware\TrustHosts::class,     ← کامنت‌شده
        |
        |   git log -S "TrustHosts::class" <L10> -- app/Http/Kernel.php
        |       → bc91d43 "first commit"  (تنها کامیت)
        |   جست‌وجوی فرمِ بدونِ //  → هیچ نتیجه‌ای
        |
        | یعنی روی L10 از **اولین کامیتِ ریپو** کامنت‌شده بوده و هرگز فعال نبوده.
        | خودِ این فراخوانی درست و لازم است و چیزی را عوض نمی‌کند؛ فقط روایتش غلط
        | بود. و روایتِ غلط بی‌ضرر نیست: خواننده نتیجه می‌گرفت «L10 سالم است و فقط
        | L13 شکاف داشت»، در حالی که **شکاف مالِ L10 است و ارثی است، نه رگرسیونِ
        | پورت**. اگر L10 هنوز جایی مستقر می‌شود، آن‌جا این حفاظت وجود ندارد.
        |
        | بدونِ آرگومان یعنی «همان دامنهٔ APP_URL و زیردامنه‌هایش» — دقیقاً همان
        | چیزی که `TrustHosts::hosts()` روی L10 برمی‌گرداند. خودِ فریم‌ورک در محیطِ
        | local و هنگام اجرای تست‌ها اعمالش نمی‌کند.
        */
        $middleware->trustHosts();

        /*
        |----------------------------------------------------------------------
        | API Middleware Group
        |----------------------------------------------------------------------
        | SecurityHeaders قبل از middleware پیش‌فرض API اضافه می‌شود.
        | EnsureFrontendRequestsAreStateful از Sanctum به اول گروه اضافه می‌شود
        | تا session-based و token-based هر دو کار کنند.
        |
        | CORS اینجا نیست و نباید باشد: تنها متولیِ آن HandleCors سراسری است که
        | config/cors.php را می‌خواند. قبلاً App\Http\Middleware\Cors با allowlistِ
        | هاردکدِ خودش (localhost:3000/8000/5173/5174) در همین گروه بود و چون هدر را
        | در مسیر خروج بی‌قید می‌نوشت، مقدارِ HandleCors را بازنویسی می‌کرد و
        | گِیتِ APP_ENV=local را خنثی می‌کرد — روی پروداکشن هم آن مبدأها با
        | Allow-Credentials مجاز می‌شدند. برای افزودن مبدأ از CORS_EXTRA_ORIGINS
        | استفاده کنید، نه میدل‌ور.
        */
        $middleware->group('api', [
            SecurityHeaders::class,
            EnsureFrontendRequestsAreStateful::class,
            ThrottleRequests::class.':api',
            // کاربر غیرفعال‌شده (is_active=false) با هیچ توکنی نباید سرویس بگیرد —
            // بدون این، toggleStatus ادمین صرفاً یک فلگ بی‌اثر بود.
            //
            // ⚠️ **پیش از** SubstituteBindings، دقیقاً مثل شاخهٔ L10. اگر بعد از آن
            // باشد، درخواستِ کاربرِ غیرفعال اول کوئریِ route-model-binding را می‌زند و
            // برای شناسه‌ی ناموجود ۴۰۴ می‌گیرد به‌جای ۴۰۳ — یعنی هم پاسخ با آن شاخه
            // فرق می‌کند و هم برای کاربری که اصلاً نباید سرویس بگیرد به دیتابیس
            // می‌زنیم. این ترتیب در پورت جابه‌جا شده بود.
            EnsureUserIsActive::class,
            SubstituteBindings::class,
        ]);

        /*
        |----------------------------------------------------------------------
        | Middleware Aliases
        |----------------------------------------------------------------------
        */
        $middleware->alias([
            'auth' => Authenticate::class,
            'auth.basic' => AuthenticateWithBasicAuth::class,
            'auth.session' => AuthenticateSession::class,
            'cache.headers' => SetCacheHeaders::class,
            'can' => Authorize::class,
            'guest' => RedirectIfAuthenticated::class,
            'password.confirm' => RequirePassword::class,
            'precognitive' => HandlePrecognitiveRequests::class,
            'signed' => ValidateSignature::class,
            'throttle' => ThrottleRequests::class,
            'verified' => EnsureEmailIsVerified::class,
            // عمداً هیچ alias ای برای cors نیست — بالا را ببینید.
            'SecurityHeaders' => SecurityHeaders::class,
            'attach.price.category' => AttachActivePriceCategory::class,
            'role.admin' => EnsureUserIsAdmin::class,
            'role.elite_yar' => EnsureUserIsEliteYAR::class,
            'role.employer' => EnsureUserIsEmployer::class,
            'role.supplier' => EnsureUserIsSupplier::class,
            'admin.permission' => EnsureAdminHasPermission::class,
            'admin.level' => EnsureAdminHasLevel::class,
            'admin.ip' => RestrictAdminByIp::class,
            'track' => TrackDashboardUsage::class,
            'idempotency' => Idempotency::class,
            'role.selected' => EnsureRoleIsSelected::class,
            'can-see-ticket' => CanSeeTicket::class,
            'optional.sanctum' => OptionalSanctum::class,
        ]);

        /*
        |----------------------------------------------------------------------
        | TrimStrings exceptions
        |----------------------------------------------------------------------
        */
        // ‏`TrimStrings` تنها trim‌کنندهٔ ورودی است. میدل‌ورِ `Sanitizer` که همین
        // کار را تکرار می‌کرد حذف شد — یک پیمایشِ کاملِ اضافی از payload در هر
        // درخواست، و بدتر: بدونِ هیچ استثنایی. یعنی همین فهرستِ زیر را بی‌اثر
        // می‌کرد و رمز عبور را هم trim می‌کرد.
        $middleware->trimStrings(except: [
            'current_password',
            'password',
            'password_confirmation',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->dontFlash([
            'current_password',
            'password',
            'password_confirmation',
        ]);

        // Sentry — فقط وقتی DSN ست باشد فعال است (بدون DSN کاملاً بی‌اثر).
        $exceptions->report(function (Throwable $e) {
            if (app()->bound('sentry')) {
                app('sentry')->captureException($e);
            }
        });

        /*
        |----------------------------------------------------------------------
        | API Exception Rendering
        |----------------------------------------------------------------------
        | تمام خطاهای API با فرمت یکسان JSON برگردانده می‌شوند.
        | این render قبل از render پیش‌فرض لاراول اجرا می‌شود.
        */
        $exceptions->render(function (Throwable $e, Request $request) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return (new Handler(app()))->handleApiException($e);
            }
        });
    })
    ->create();
