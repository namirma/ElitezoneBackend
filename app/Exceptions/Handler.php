<?php

namespace App\Exceptions;

use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Throwable;

class Handler extends ExceptionHandler
{
    /**
     * The list of the inputs that are never flashed to the session on validation exceptions.
     *
     * @var array<int, string>
     */
    protected $dontFlash = [
        'current_password',
        'password',
        'password_confirmation',
    ];

    /**
     * Register the exception handling callbacks for the application.
     */
    public function register(): void
    {
        $this->reportable(function (Throwable $e) {
            //
        });

        $this->renderable(function (ThrottleRequestsException $e, $request) {

            // چک می‌کنیم که درخواست برای API باشد
            if ($request->wantsJson() || $request->is('api/*')) {

                // محاسبه زمان باقی‌مانده (به دقیقه)
                $retryAfterSeconds = $e->getHeaders()['Retry-After'] ?? 60;
                $minutes = (int) ceil($retryAfterSeconds / 60);

                // 🚀 3. استفاده از هلپر error() و پیام فارسی شما
                return error(
                    __('auth.throttle', ['minutes' => $minutes]),
                    [], // آرایه خطاها خالی است
                    429 // 429 Too Many Attempts
                );
            }
        });
    }
}
