<?php

namespace App\Exceptions;

use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use Throwable;

class Handler extends ExceptionHandler
{
    /**
     * لیست ورودی‌هایی که در هنگام خطای ولیدیشن نباید در سشن فلش شوند.
     *
     * @var array<int, string>
     */
    protected $dontFlash = [
        'current_password',
        'password',
        'password_confirmation',
    ];

    /**
     * ثبت کال‌بک‌های مدیریت خطا.
     */
    public function register(): void
    {
        $this->reportable(function (Throwable $e) {
            //
        });

        // مدیریت متمرکز تمام خطاها برای درخواست‌های API
        $this->renderable(function (Throwable $e, $request) {
            if ($request->wantsJson() || $request->is('api/*')) {
                return $this->handleApiException($e);
            }
        });
    }

    /**
     * مدیریت مرکزی خطاهای API و تبدیل به پاسخ استاندارد JSON
     */
    private function handleApiException(Throwable $e)
    {
        // ۱. خطای پیدا نشدن (404)
        if ($e instanceof NotFoundHttpException || $e instanceof ModelNotFoundException) {
            $message = '.مسیر مورد نظر یافت نشد';

            if ($e instanceof ModelNotFoundException || $e->getPrevious() instanceof ModelNotFoundException) {
                $message = __('global.model_not_found') ?? '.آیتم مورد نظر یافت نشد';
            }

            return error($message, [], 404);
        }

        // ۲.خطای محدودیت تعداد درخواست (429)
        if ($e instanceof ThrottleRequestsException) {
            $retryAfterSeconds = $e->getHeaders()['Retry-After'] ?? 60;
            $minutes = (int) ceil($retryAfterSeconds / 60);

            return error(
                __('auth.throttle', ['minutes' => $minutes]),
                [],
                429
            );
        }

        // ۳. خطای اعتبارسنجی (422)
        if ($e instanceof ValidationException) {
            return error(
                $e->getMessage(),
                ['errors' => $e->errors()],
                422
            );
        }

        // ۴. خطای احراز هویت (401)
        if ($e instanceof AuthenticationException) {
            return error(__('auth.unauthenticated') ?? '.لطفاً ابتدا وارد حساب کاربری شوید', [], 401);
        }

        // ۵. خطای متد غیرمجاز (405)
        if ($e instanceof MethodNotAllowedHttpException) {
            return error('.متد ارسالی برای این مسیر مجاز نیست', [], 405);
        }

        // ۶. خطای دیتابیس (500)
        if ($e instanceof QueryException) {
            Log::error('Database Error: ' . $e->getMessage());
            $message = config('app.debug') ? $e->getMessage() : '.خطای پایگاه داده رخ داده است';
            return error($message, [], 500);
        }

        // ۷. سایر خطاهای سرور (500)
        Log::error('Server Error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
        Log::error($e->getTraceAsString());

        // تعیین پیام خطا
        $message = $e->getMessage();

        if (!config('app.debug') || empty($message)) {
            $message = '.خطای داخلی سرور رخ داده است';
        }

        return error($message, [], 500);
    }
}
