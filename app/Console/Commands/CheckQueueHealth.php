<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ops\QueueHealthService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * پایشِ سلامتِ صف — «worker خوابیده» را از یک خرابیِ خاموش به یک خطای صریح تبدیل می‌کند.
 *
 * همهٔ listenerهای پروژه ShouldQueue هستند و صف روی درایورِ database است. اگر
 * workerها متوقف شوند هیچ استثنایی پرتاب نمی‌شود: پیامک OTP، اعلان سفارش و ایمیل
 * صرفاً در جدول `jobs` می‌مانند. چون ورود کاربر به پیامک OTP وابسته است، این
 * خرابیِ خاموش عملاً یعنی قطعیِ ورود — بدون هیچ ردی در لاگ.
 *
 * خروجی: exit code 0 سالم، 1 هشدار. مانیتورینگِ بیرونی (cron wrapper/uptime check)
 * می‌تواند روی همین کد alert بگذارد؛ لاگِ `error` هم اگر Sentry DSN ست باشد به
 * error-tracker می‌رود.
 *
 * قواعد و آستانه‌ها در QueueHealthService است تا صفحهٔ سلامتِ سامانه هم دقیقاً
 * همان‌ها را ببیند.
 */
class CheckQueueHealth extends Command
{
    protected $signature = 'queue:health {--json : خروجی ماشین‌خوان برای مانیتورینگ}';

    protected $description = 'بررسی سلامت صف: سن و حجم jobهای منتظر و تعداد شکست‌ها';

    public function handle(QueueHealthService $queueHealth): int
    {
        $report = $queueHealth->report();

        if (! $report['applicable']) {
            $this->info('queue:health فقط برای درایور database است؛ رد شد (درایور فعلی: '.$report['driver'].').');

            return self::SUCCESS;
        }

        $stats = $report['stats'];
        $problems = $report['problems'];

        if ($this->option('json')) {
            $this->line((string) json_encode(
                $stats + ['healthy' => $report['healthy'], 'problems' => $problems],
                JSON_UNESCAPED_UNICODE
            ));
        }

        if ($problems === []) {
            if (! $this->option('json')) {
                $this->info(sprintf(
                    'صف سالم است — منتظر: %d، قدیمی‌ترین: %s، شکست‌ها: %d.',
                    $stats['pending'],
                    $stats['oldest_pending_age'] === null ? '—' : $stats['oldest_pending_age'].'s',
                    $stats['failed_total']
                ));
            }

            return self::SUCCESS;
        }

        // سطح error (نه warning): این وضعیت یعنی کاربران همین حالا پیامک نمی‌گیرند.
        Log::error('Queue health check failed', $stats + ['problems' => $problems]);

        foreach ($problems as $problem) {
            $this->error($problem);
        }

        return self::FAILURE;
    }
}
