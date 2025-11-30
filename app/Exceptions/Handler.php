<?php

namespace App\Exceptions;

use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
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
            if ($request->wantsJson() || $request->is('api/*')) {
                $retryAfterSeconds = $e->getHeaders()['Retry-After'] ?? 60;
                $minutes = (int) ceil($retryAfterSeconds / 60);

                return error(
                    __('auth.throttle', ['minutes' => $minutes]),
                    [],
                    429
                );
            }
        });

        $this->renderable(function (NotFoundHttpException $e, $request) {
            if ($request->wantsJson() || $request->is('api/*')) {

                $message = '.مسیر مورد نظر یافت نشد';

                if ($e->getPrevious() instanceof ModelNotFoundException) {
                    $message = __('global.model_not_found') ?? '.آیتم مورد نظر یافت نشد';
                }

                return error($message, [], 404);
            }
        });
    }
}
