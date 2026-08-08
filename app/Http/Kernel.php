<?php

namespace App\Http;

use App\Http\Middleware\AttachActivePriceCategory;
use App\Http\Middleware\Authenticate;
use App\Http\Middleware\CanSeeTicket;
use App\Http\Middleware\EncryptCookies;
use App\Http\Middleware\EnsureRoleIsSelected;
use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\EnsureUserIsAdmin;
use App\Http\Middleware\EnsureUserIsEliteYAR;
use App\Http\Middleware\EnsureUserIsEmployer;
use App\Http\Middleware\EnsureUserIsSupplier;
use App\Http\Middleware\Idempotency;
use App\Http\Middleware\OptionalSanctum;
use App\Http\Middleware\PreventRequestsDuringMaintenance;
use App\Http\Middleware\RedirectIfAuthenticated;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\TrackDashboardUsage;
use App\Http\Middleware\TrimStrings;
use App\Http\Middleware\TrustProxies;
use App\Http\Middleware\ValidateSignature;
use App\Http\Middleware\VerifyCsrfToken;
use Illuminate\Auth\Middleware\AuthenticateWithBasicAuth;
use Illuminate\Auth\Middleware\Authorize;
use Illuminate\Auth\Middleware\EnsureEmailIsVerified;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Foundation\Http\Kernel as HttpKernel;
use Illuminate\Foundation\Http\Middleware\ConvertEmptyStringsToNull;
use Illuminate\Foundation\Http\Middleware\HandlePrecognitiveRequests;
use Illuminate\Foundation\Http\Middleware\ValidatePostSize;
use Illuminate\Http\Middleware\HandleCors;
use Illuminate\Http\Middleware\SetCacheHeaders;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Session\Middleware\AuthenticateSession;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;

class Kernel extends HttpKernel
{
    /**
     * The application's global HTTP middleware stack.
     *
     * These middleware are run during every request to your application.
     *
     * @var array<int, class-string|string>
     */
    protected $middleware = [
        // \App\Http\Middleware\TrustHosts::class,
        TrustProxies::class,
        HandleCors::class,
        PreventRequestsDuringMaintenance::class,
        ValidatePostSize::class,
        // TrimStrings تنها trim کنندهٔ ورودی است — میدل‌ور Sanitizer که همین کار را
        // دوباره تکرار می‌کرد حذف شد (یک پیمایش کامل اضافی از payload در هر درخواست).
        TrimStrings::class,
        ConvertEmptyStringsToNull::class,
        // StartSession/ShareErrorsFromSession از استک سراسری خارج شدند: احراز هویت API
        // با توکن Sanctum است و session لازم ندارد؛ درخواست‌های stateful (کوکی SPA) را
        // خودِ EnsureFrontendRequestsAreStateful با استک session مخصوص Sanctum wrap
        // می‌کند. این دو فقط در گروه web (که به session/CSRF نیاز دارد) باقی می‌مانند.
    ];

    /**
     * The application's route middleware groups.
     *
     * @var array<string, array<int, class-string|string>>
     */
    protected $middlewareGroups = [
        'web' => [
            EncryptCookies::class,
            AddQueuedCookiesToResponse::class,
            // StartSession باید قبل از VerifyCsrfToken باشد — توکن CSRF از session خوانده می‌شود.
            StartSession::class,
            ShareErrorsFromSession::class,
            VerifyCsrfToken::class,
            SubstituteBindings::class,
        ],

        'api' => [
            SecurityHeaders::class,
            EnsureFrontendRequestsAreStateful::class,
            ThrottleRequests::class.':api',
            // کاربر غیرفعال‌شده (is_active=false) با هیچ توکنی نباید سرویس بگیرد —
            // بدون این، toggleStatus ادمین صرفاً یک فلگ بی‌اثر بود.
            EnsureUserIsActive::class,
            SubstituteBindings::class,
            // EnsureRoleIsSelected is applied per-route in routes/api.php (place/checkout
            // require it; profile/role-selection explicitly opts out). Not global.
        ],
    ];

    /**
     * The application's middleware aliases.
     *
     * Aliases may be used instead of class names to conveniently assign middleware to routes and groups.
     *
     * @var array<string, class-string|string>
     */
    protected $middlewareAliases = [
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
        // NOTE: there is deliberately no 'cors' alias. CORS is owned solely by the
        // global HandleCors middleware reading config/cors.php — see RouteServiceProvider.
        'SecurityHeaders' => SecurityHeaders::class,
        'attach.price.category' => AttachActivePriceCategory::class,
        'role.admin' => EnsureUserIsAdmin::class,
        'role.elite_yar' => EnsureUserIsEliteYAR::class,
        'role.employer' => EnsureUserIsEmployer::class,
        'role.supplier' => EnsureUserIsSupplier::class,
        'admin.permission' => \App\Http\Middleware\EnsureAdminHasPermission::class,
        'admin.ip' => \App\Http\Middleware\RestrictAdminByIp::class,
        'track' => TrackDashboardUsage::class,
        'idempotency' => Idempotency::class,
        'role.selected' => EnsureRoleIsSelected::class,
        'can-see-ticket' => CanSeeTicket::class,
        'optional.sanctum' => OptionalSanctum::class,
    ];
}
