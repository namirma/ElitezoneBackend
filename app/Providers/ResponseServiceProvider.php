<?php

namespace App\Providers;

use App\Http\Classes\Response as SzResponse;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\ServiceProvider;

class ResponseServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        Response::macro('success', function ($data, string $message, int $status, array $headers = []) {
            return new SzResponse([
                'status' => true,
                'message' => $message,
                'data' => $data,
            ], $status, array_merge(
                [
                    'version' => config('app.version'),
                    'app-name' => config('app.name'),
                ],
                $headers
            ));
        });

        Response::macro('error', function ($message, array $errors, $status, array $headers = []) {
            return new SzResponse([
                'status' => false,
                'message' => $message,
                // «data» به‌صورت افزایشی اضافه شد تا قراردادِ مستندِ {status,message,data}
                // در تمام مسیرها (از جمله خطاها) برقرار بماند؛ «errors» برای سازگاری با
                // کلاینت‌های فعلی حفظ شده تا چیزی نشکند.
                'data' => null,
                'errors' => $errors,
            ], $status, array_merge(
                [
                    'version' => config('app.version'),
                    'app-name' => config('app.name'),
                ],
                $headers
            ));
        });
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void {}
}
