<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\Analytics\GenerateCsvReportJob;
use App\Services\Reports\CsvReportBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * پنل مدیریت — خروجی CSV گزارش‌ها (ماژول ۶ نیازمندی فرانت BackOffice).
 *
 * بازه‌های کوچک مستقیم استریم می‌شوند (UTF-8 با BOM). بازه‌هایی که از
 * config('reports.stream_max_rows') بزرگ‌ترند در صف تولید می‌شوند
 * (GenerateCsvReportJob) و لینک دانلود با اعلان به ادمین داده می‌شود.
 * فیلتر اختیاری بازه تاریخ (میلادی): ?from=Y-m-d&to=Y-m-d
 */
class AdminReportController extends Controller
{
    public function __construct(private readonly CsvReportBuilder $builder) {}

    // GET /admin/reports/orders
    public function orders(Request $request): StreamedResponse|JsonResponse
    {
        return $this->export('orders', $request);
    }

    // GET /admin/reports/financial
    public function financial(Request $request): StreamedResponse|JsonResponse
    {
        return $this->export('financial', $request);
    }

    // GET /admin/reports/users
    public function users(Request $request): StreamedResponse|JsonResponse
    {
        return $this->export('users', $request);
    }

    // GET /admin/reports/downloads/{filename} — دانلود گزارش تولیدشده در صف
    public function download(string $filename): StreamedResponse|JsonResponse
    {
        // فقط الگوی نام‌گذاری خود Job پذیرفته می‌شود — هیچ مسیر/کاراکتر دیگری
        if (! preg_match('/^(orders|financial|users)-report-\d{8}-\d{6}\.csv$/', $filename)) {
            return error(__('admin.reports.not_found'), [], 404);
        }

        $relativePath = 'reports/'.$filename;

        if (! Storage::disk('financial')->exists($relativePath)) {
            return error(__('admin.reports.not_found'), [], 404);
        }

        return Storage::disk('financial')->download($relativePath, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    private function export(string $type, Request $request): StreamedResponse|JsonResponse
    {
        $validated = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $from = $validated['from'] ?? null;
        $to = $validated['to'] ?? null;

        $rowCount = $this->builder->query($type, $from, $to)->count();
        $threshold = (int) config('reports.stream_max_rows', 50_000);

        if ($rowCount > $threshold) {
            GenerateCsvReportJob::dispatch($type, $from, $to, (int) Auth::id());

            return response()->json([
                'status' => true,
                'message' => __('admin.reports.queued', ['rows' => $rowCount]),
                'data' => ['queued' => true, 'rows' => $rowCount],
            ], 202);
        }

        return response()->streamDownload(function () use ($type, $from, $to) {
            $out = fopen('php://output', 'w');
            $this->builder->writeTo($out, $type, $from, $to);
            fclose($out);
        }, "{$type}-report.csv", [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }
}
