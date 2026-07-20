<?php

use App\Exceptions\Handler;
use App\Http\Middleware\AttachActivePriceCategory;
use App\Http\Middleware\Authenticate;
use App\Http\Middleware\CanSeeTicket;
use App\Http\Middleware\Cors;
use App\Http\Middleware\EnsureRoleIsSelected;
use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\EnsureUserIsAdmin;
use App\Http\Middleware\EnsureUserIsEmployer;
use App\Http\Middleware\EnsureUserIsExpert;
use App\Http\Middleware\EnsureUserIsSupplier;
use App\Http\Middleware\Idempotency;
use App\Http\Middleware\OptionalSanctum;
use App\Http\Middleware\RedirectIfAuthenticated;
use App\Http\Middleware\Sanitizer;
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
    ->withMiddleware(function (Middleware $middleware) {
        /*
        |----------------------------------------------------------------------
        | Global Middleware (every request)
        |----------------------------------------------------------------------
        */
        $middleware->append(Sanitizer::class);

        /*
        |----------------------------------------------------------------------
        | API Middleware Group
        |----------------------------------------------------------------------
        | SecurityHeaders و Cors به ترتیب قبل و بعد از middleware پیش‌فرض
        | aPI اضافه می‌شوند. EnsureFrontendRequestsAreStateful از Sanctum به
        | اول گروه اضافه می‌شود تا session-based و token-based هر دو کار کنند.
        */
        $middleware->group('api', [
            SecurityHeaders::class,
            EnsureFrontendRequestsAreStateful::class,
            ThrottleRequests::class.':api',
            SubstituteBindings::class,
            // کاربر غیرفعال‌شده (is_active=false) با هیچ توکنی نباید سرویس بگیرد —
            // بدون این، toggleStatus ادمین صرفاً یک فلگ بی‌اثر بود.
            EnsureUserIsActive::class,
            Cors::class,
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
            'cors' => Cors::class,
            'SecurityHeaders' => SecurityHeaders::class,
            'attach.price.category' => AttachActivePriceCategory::class,
            'role.admin' => EnsureUserIsAdmin::class,
            'role.expert' => EnsureUserIsExpert::class,
            'role.employer' => EnsureUserIsEmployer::class,
            'role.supplier' => EnsureUserIsSupplier::class,
            'admin.permission' => \App\Http\Middleware\EnsureAdminHasPermission::class,
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
