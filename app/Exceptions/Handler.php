<?php

namespace App\Exceptions;

use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Exceptions\HttpResponseException; // <--- ایمپورت مهم
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\Routing\Exception\RouteNotFoundException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface; // <--- ایمپورت مهم
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
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

    // متد unauthenticated پیش‌فرض لاراول را بازنویسی می‌کنیم
    protected function unauthenticated($request, AuthenticationException $exception)
    {
        if ($request->expectsJson() || $request->is('api/*')) {
            return response()->json([
                'status'  => false,
                'message' => 'احراز هویت نشده‌اید. لطفاً وارد حساب کاربری شوید.',
                'errors'  => [],
            ], 401);
        }
        return redirect()->guest(route('login'));
    }

    private function handleApiException(Throwable $e)
    {
        // 1. اگر خطا خودش یک پاسخ HTTP معتبر است (مثل زمانی که یک میدلور پاسخ می‌دهد)
        // این همان چیزی است که خطای helpers.php:40 را حل می‌کند
        if ($e instanceof HttpResponseException) {
            return $e->getResponse();
        }

        // 2. احراز هویت (401)
        if ($e instanceof AuthenticationException) {
            return $this->jsonResponse('احراز هویت نشده‌اید. لطفاً وارد حساب کاربری شوید.', 401);
        }

        // 3. مشکل روت لاگین (ریدایرکت ناموفق)
        if ($e instanceof RouteNotFoundException) {
            if (str_contains($e->getMessage(), 'Route [login] not defined')) {
                return $this->jsonResponse('احراز هویت نشده‌اید. لطفاً وارد حساب کاربری شوید.', 401);
            }
            return $this->jsonResponse('مسیر مورد نظر یافت نشد.', 404);
        }

        // 4. عدم دسترسی (403)
        if ($e instanceof AuthorizationException) {
            return $this->jsonResponse('شما اجازه دسترسی به این بخش را ندارید.', 403);
        }

        // 5. یافت نشد (404)
        if ($e instanceof NotFoundHttpException || $e instanceof ModelNotFoundException) {
            $message = 'مسیر مورد نظر یافت نشد.';
            if ($e instanceof ModelNotFoundException || $e->getPrevious() instanceof ModelNotFoundException) {
                $message = __('global.model_not_found') ?: 'آیتم مورد نظر یافت نشد.';
            }
            return $this->jsonResponse($message, 404);
        }

        // 6. سایر خطاهای HTTP استاندارد (مثل abort(403) یا abort(503))
        if ($e instanceof HttpExceptionInterface) {
            return $this->jsonResponse($e->getMessage(), $e->getStatusCode());
        }

        // 7. ولیدیشن (422)
        if ($e instanceof ValidationException) {
            return response()->json([
                'status'  => false,
                'message' => $e->getMessage(),
                'errors'  => $e->errors(),
            ], 422);
        }

        // 8. دیتابیس (500)
        if ($e instanceof QueryException) {
            Log::error('Database Error: ' . $e->getMessage());
            $message = config('app.debug') ? $e->getMessage() : 'خطای پایگاه داده رخ داده است.';
            return $this->jsonResponse($message, 500);
        }

        // 9. خطای ناشناخته (500)
        Log::error('Server Error (' . get_class($e) . '): ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());

        $message = $e->getMessage();
        if (!config('app.debug') || empty($message)) {
            $message = 'خطای داخلی سرور رخ داده است.';
        }

        return $this->jsonResponse($message, 500);
    }

    private function jsonResponse($message, $code)
    {
        return response()->json([
            'status'  => false,
            'message' => $message,
            'errors'  => []
        ], $code);
    }
}
