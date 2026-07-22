<?php

namespace App\Jobs\Analytics;

use App\Models\UserNotification;
use App\Services\Reports\CsvReportBuilder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * تولید گزارش CSV سنگین در پس‌زمینه.
 *
 * فایل روی دیسک خصوصی financial (reports/) نوشته می‌شود و به ادمین درخواست‌دهنده
 * یک اعلان درون‌برنامه‌ای با نام فایل داده می‌شود؛ دانلود از
 * GET /admin/reports/downloads/{filename} (پشت role.admin).
 */
class GenerateCsvReportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 1800;

    public int $tries = 3;

    /** @var list<int> ثانیه تا تلاش بعدی */
    public array $backoff = [120, 600];

    public function __construct(
        public readonly string $type,
        public readonly ?string $from,
        public readonly ?string $to,
        public readonly int $adminId,
    ) {}

    public function handle(CsvReportBuilder $builder): void
    {
        $filename = sprintf('%s-report-%s.csv', $this->type, now()->format('Ymd-His'));
        $relativePath = 'reports/'.$filename;

        Storage::disk('financial')->makeDirectory('reports');

        $absolute = Storage::disk('financial')->path($relativePath);
        $stream = fopen($absolute, 'w');
        $rows = $builder->writeTo($stream, $this->type, $this->from, $this->to);
        fclose($stream);

        UserNotification::create([
            UserNotification::USER_ID => $this->adminId,
            UserNotification::SENDER_ID => null,
            UserNotification::SUBJECT => __('admin.reports.ready_subject'),
            UserNotification::BODY => __('admin.reports.ready_body', [
                'type' => $this->type,
                'rows' => $rows,
                'filename' => $filename,
            ]),
        ]);
    }

    public function failed(\Throwable $e): void
    {
        Log::error('GenerateCsvReportJob failed after retries', [
            'type' => $this->type,
            'from' => $this->from,
            'to' => $this->to,
            'error' => $e->getMessage(),
        ]);

        // به ادمین درخواست‌دهنده اطلاع بده که گزارش ساخته نشد (وگرنه بی‌خبر منتظر می‌ماند)
        UserNotification::create([
            UserNotification::USER_ID => $this->adminId,
            UserNotification::SENDER_ID => null,
            UserNotification::SUBJECT => __('admin.reports.failed_subject'),
            UserNotification::BODY => __('admin.reports.failed_body', ['type' => $this->type]),
        ]);
    }
}
