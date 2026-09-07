<?php

namespace App\Exceptions;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Exception\RouteNotFoundException;
use Throwable;

class Handler extends ExceptionHandler
{
    protected $dontFlash = [
        'current_password',
        'password',
        'password_confirmation',
    ];

    public function register(): void
    {
        // Sentry — فقط وقتی DSN ست باشد فعال است (بدون DSN کاملاً بی‌اثر).
        // خطاهای گزارش‌شدنی (بعد از dontReport) به error-tracker می‌روند.
        $this->reportable(function (Throwable $e) {
            if (app()->bound('sentry')) {
                app('sentry')->captureException($e);
            }
        });

        $this->renderable(function (Throwable $e, $request) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return $this->handleApiException($e);
            }
        });
    }

    // متد unauthenticated برای زمانی که توکن کاربر معتبر نیست
    protected function unauthenticated($request, AuthenticationException $exception)
    {
        if ($request->expectsJson() || $request->is('api/*')) {
            return response()->json([
                'status' => false,
                'message' => __('global.errors.unauthenticated'),
                'data' => null,
                'errors' => [],
            ], 401);
        }

        return redirect()->guest(route('login'));
    }

    private function handleApiException(Throwable $e)
    {
        // 1. خطاهای HTTP سفارشی (response آماده)
        if ($e instanceof HttpResponseException) {
            return $e->getResponse();
        }

        // 2. احراز هویت (401) — renderable() runs BEFORE Laravel's unauthenticated()
        //    hook, so we must handle this here to return 401 (not 500).
        if ($e instanceof AuthenticationException) {
            return $this->jsonResponse(__('global.errors.unauthenticated'), 401);
        }

        // 3. مشکل روت لاگین
        if ($e instanceof RouteNotFoundException) {
            if (str_contains($e->getMessage(), 'Route [login] not defined')) {
                return $this->jsonResponse(__('global.errors.unauthenticated'), 401);
            }

            return $this->jsonResponse(__('global.errors.route_not_found'), 404);
        }

        // 4. عدم دسترسی (AuthorizationException - معمولاً توسط Policyهای لاراول)
        if ($e instanceof AuthorizationException) {
            $message = $e->getMessage() ?: __('global.errors.unauthorized_role');

            return $this->jsonResponse($message, 403);
        }

        // 5. یافت نشد (404)
        if ($e instanceof NotFoundHttpException || $e instanceof ModelNotFoundException) {
            $message = __('global.errors.item_not_found');
            if ($e instanceof ModelNotFoundException) {
                $message = __('global.errors.record_not_found');
            }

            return $this->jsonResponse($message, 404);
        }

        if ($e instanceof HttpExceptionInterface) {
            $statusCode = $e->getStatusCode();
            $message = $e->getMessage();

            if ($statusCode === 403) {
                $message = __('global.errors.unauthorized_action');
            }

            // ۴۲۹ از دو جا می‌آید: limiterهایی که خودشان `response()` دارند (که
            // اصلاً به اینجا نمی‌رسند)، و `throttle:N,1`های ساده که پیامِ
            // پیش‌فرضِ لاراول را می‌آورند — «Too Many Attempts.»، انگلیسی، در
            // اپی که `locale=fa` است و قاعده‌اش «هیچ رشتهٔ کاربرپسندی بدون
            // __()» است. کلیدش از قبل وجود داشت و فقط این مسیر به آن وصل نبود.
            if ($statusCode === 429) {
                $message = __('global.too_many_requests');
            }

            // اگر پیام خالی بود (مثلا abort(500))
            if (empty($message)) {
                $message = match ($statusCode) {
                    403 => __('global.errors.forbidden_short'),
                    404 => __('global.errors.not_found_short'),
                    500 => __('global.errors.server_error_short'),
                    default => __('global.errors.generic_request_error'),
                };
            }

            return $this->jsonResponse($message, $statusCode);
        }

        // 7. ولیدیشن (422)
        if ($e instanceof ValidationException) {
            return response()->json([
                'status' => false,
                'message' => __('global.errors.validation_failed'), // پیام کلی
                'data' => null,
                'errors' => $e->errors(), // جزئیات فیلدها
            ], 422);
        }

        // 8. دیتابیس (500)
        if ($e instanceof QueryException) {
            // لاگ کردن خطای اصلی برای برنامه‌نویس
            Log::error('Database Error: '.$e->getMessage());

            // در حالت پروداکشن نباید خطای SQL را به کاربر نشان داد. گاردِ دولایه:
            // حتی اگر به‌اشتباه APP_DEBUG=true روی پروداکشن بماند، پیام SQL لو نمی‌رود.
            $message = (config('app.debug') && ! app()->environment('production'))
                ? $e->getMessage()
                : __('global.errors.database_error');

            return $this->jsonResponse($message, 500);
        }

        // 9. سایر خطاهای سرور (500)
        Log::error('Server Error: '.$e->getMessage(), ['exception' => $e]);

        $message = (config('app.debug') && ! app()->environment('production'))
            ? $e->getMessage()
            : __('global.errors.internal_server_error');

        return $this->jsonResponse($message, 500);
    }

    // متد کمکی برای ساخت پاسخ جیسون
    private function jsonResponse($message, $code)
    {
        return response()->json([
            'status' => false,
            'message' => $message,
            'data' => null,
            'errors' => [],
        ], $code);
    }
}
