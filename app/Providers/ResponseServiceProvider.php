<?php

namespace App\Providers;

use App\Http\Classes\Response as SzResponse;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Illuminate\Pagination\AbstractPaginator;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\ServiceProvider;

class ResponseServiceProvider extends ServiceProvider
{
    /**
     * اطلاعاتِ صفحه‌بندی را در کنارِ `data` بیرون می‌دهد.
     *
     * ## باگ
     *
     * وقتی یک `ResourceCollection` **تودرتو** سریالایز شود (نه به‌عنوان پاسخِ
     * اصلی)، لاراول فقط `toArray()` را صدا می‌زند؛ `links`/`meta` را
     * `toResponse()` اضافه می‌کند که هرگز اجرا نمی‌شود. پس
     * `success(XResource::collection($paginator))` — الگوی **۴۹ اندپوینتِ
     * فهرست** در پنل ادمین و پنل تأمین‌کننده — شمارشِ کل و شماره‌ی صفحه را دور
     * می‌ریخت، در حالی که کوئریِ `COUNT` و `LIMIT/OFFSET` هر دو اجرا شده بودند.
     *
     * یعنی هیچ فهرستی در هیچ‌کدام از دو پنل صفحه‌بندی نداشت: کلاینت صفحه‌ی اول
     * را می‌گرفت و هیچ راهی نداشت بفهمد صفحه‌ی دومی هست یا نه.
     *
     * ## چرا کنارِ `data` و نه داخلش
     *
     * هر دو سندِ منتشرشده شکلِ `data: {data, links, meta}` را وعده داده‌اند، ولی
     * کد هرگز آن را نداده و کلاینت‌ها روی شکلِ واقعی (آرایه‌ی مستقیم در `data`)
     * نوشته شده‌اند. تودرتوکردن یعنی شکستنِ ۴۹ اندپوینت برای قراردادی که امروز
     * هیچ مصرف‌کننده‌ای ندارد.
     *
     * پس `data` همان آرایه می‌ماند و `meta`/`links` **هم‌سطحِ** آن اضافه
     * می‌شوند: کلاینتِ فعلی دست‌نخورده کار می‌کند و صفحه‌بندی — که تا امروز
     * غایب بود — در دسترس قرار می‌گیرد. سندها باید با همین هم‌راستا شوند.
     *
     * فقط وقتی دخالت می‌کند که مجموعه **واقعاً صفحه‌بندی‌شده** باشد؛ مجموعه‌ی
     * ساده هیچ تغییری نمی‌کند.
     *
     * @return array{data: mixed, meta?: array<string, mixed>, links?: array<string, mixed>}
     */
    public static function paginationEnvelope(mixed $data): array
    {
        if ($data instanceof ResourceCollection && $data->resource instanceof AbstractPaginator) {
            $payload = $data->response()->getData(true);

            return array_filter([
                'data' => $payload['data'] ?? [],
                'meta' => $payload['meta'] ?? null,
                'links' => $payload['links'] ?? null,
            ], static fn ($v) => $v !== null);
        }

        // صفحه‌بندیِ خام (مثلاً `$paginator->through(...)`) شکلِ **تخت** دارد:
        // `current_page`, `total`, `data`, `first_page_url`, … . یعنی سه شکلِ
        // متفاوت برای یک مفهوم در یک API. اینجا به همان `{data, meta, links}`
        // نگاشت می‌شود تا هر فهرستی در هر دو پنل یک قرارداد داشته باشد.
        if ($data instanceof AbstractPaginator) {
            $flat = $data->toArray();

            $links = array_intersect_key($flat, array_flip([
                'first_page_url', 'last_page_url', 'prev_page_url', 'next_page_url',
            ]));

            $meta = array_diff_key($flat, $links + ['data' => null, 'links' => null]);

            return [
                'data' => $flat['data'] ?? [],
                'meta' => $meta,
                'links' => $links,
            ];
        }

        return ['data' => $data];
    }

    /**
     * Register services.
     */
    public function register(): void
    {
        Response::macro('success', function ($data, string $message, int $status, array $headers = []) {
            return new SzResponse([
                'status' => true,
                'message' => $message,
                ...ResponseServiceProvider::paginationEnvelope($data),
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
