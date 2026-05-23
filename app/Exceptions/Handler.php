<?php

namespace App\Exceptions;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;  // used by unauthenticated() hook
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
                'message' => 'احراز هویت نشده‌اید. لطفاً وارد حساب کاربری شوید.',
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

        // توجه: AuthenticationException اینجا نمی‌رسد — Laravel آن را قبل از
        // renderable() به متد unauthenticated() تحویل می‌دهد. حذف شده تا dead
        // code نباشد.

        // 2. مشکل روت لاگین
        if ($e instanceof RouteNotFoundException) {
            if (str_contains($e->getMessage(), 'Route [login] not defined')) {
                return $this->jsonResponse('احراز هویت نشده‌اید. لطفاً وارد حساب کاربری شوید.', 401);
            }

            return $this->jsonResponse('مسیر مورد نظر یافت نشد.', 404);
        }

        // 4. عدم دسترسی (AuthorizationException - معمولاً توسط Policyهای لاراول)
        if ($e instanceof AuthorizationException) {
            return $this->jsonResponse('شما نقش یا مجوز لازم برای دسترسی به این بخش را ندارید.', 403);
        }

        // 5. یافت نشد (404)
        if ($e instanceof NotFoundHttpException || $e instanceof ModelNotFoundException) {
            $message = 'مسیر یا آیتم مورد نظر یافت نشد.';
            if ($e instanceof ModelNotFoundException) {
                $message = 'رکوردی با مشخصات وارد شده یافت نشد.';
            }

            return $this->jsonResponse($message, 404);
        }

        if ($e instanceof HttpExceptionInterface) {
            $statusCode = $e->getStatusCode();
            $message = $e->getMessage();

            if ($statusCode === 403) {
                $message = 'شما دسترسی لازم برای انجام این عملیات را ندارید.';
            }

            // اگر پیام خالی بود (مثلا abort(500))
            if (empty($message)) {
                $message = match ($statusCode) {
                    403 => 'دسترسی غیرمجاز',
                    404 => 'یافت نشد',
                    500 => 'خطای سرور',
                    default => 'خطای درخواست',
                };
            }

            return $this->jsonResponse($message, $statusCode);
        }

        // 7. ولیدیشن (422)
        if ($e instanceof ValidationException) {
            return response()->json([
                'status' => false,
                'message' => 'اطلاعات ارسالی معتبر نیست.', // پیام کلی
                'errors' => $e->errors(), // جزئیات فیلدها
            ], 422);
        }

        // 8. دیتابیس (500)
        if ($e instanceof QueryException) {
            // لاگ کردن خطای اصلی برای برنامه‌نویس
            Log::error('Database Error: '.$e->getMessage());

            // در حالت پروداکشن نباید خطای SQL را به کاربر نشان داد
            $message = config('app.debug') ? $e->getMessage() : 'خطای پایگاه داده رخ داده است.';

            return $this->jsonResponse($message, 500);
        }

        // 9. سایر خطاهای سرور (500)
        Log::error('Server Error: '.$e->getMessage(), ['exception' => $e]);

        $message = config('app.debug') ? $e->getMessage() : 'خطای داخلی سرور رخ داده است.';

        return $this->jsonResponse($message, 500);
    }

    // متد کمکی برای ساخت پاسخ جیسون
    private function jsonResponse($message, $code)
    {
        return response()->json([
            'status' => false,
            'message' => $message,
            'errors' => [],
        ], $code);
    }
}
