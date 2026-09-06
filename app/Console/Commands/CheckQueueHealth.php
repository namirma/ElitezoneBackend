<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
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
 */
class CheckQueueHealth extends Command
{
    protected $signature = 'queue:health {--json : خروجی ماشین‌خوان برای مانیتورینگ}';

    protected $description = 'بررسی سلامت صف: سن و حجم jobهای منتظر و تعداد شکست‌ها';

    public function handle(): int
    {
        // فقط برای درایورِ database معنا دارد؛ روی redis/sqs متریک‌ها جای دیگری‌اند.
        if (config('queue.default') !== 'database') {
            $this->info('queue:health فقط برای درایور database است؛ رد شد (درایور فعلی: '.config('queue.default').').');

            return self::SUCCESS;
        }

        $problems = [];
        $stats = $this->collectStats();

        if ($stats['oldest_pending_age'] !== null
            && $stats['oldest_pending_age'] > config('ops.queue.max_pending_age_seconds')) {
            $problems[] = sprintf(
                'قدیمی‌ترین job منتظر %d ثانیه سن دارد (آستانه %d) — به احتمال زیاد worker متوقف است.',
                $stats['oldest_pending_age'],
                config('ops.queue.max_pending_age_seconds')
            );
        }

        if ($stats['pending'] > config('ops.queue.max_pending_jobs')) {
            $problems[] = sprintf(
                '%d job در صف منتظر است (آستانه %d) — صف در حال انباشت.',
                $stats['pending'],
                config('ops.queue.max_pending_jobs')
            );
        }

        if ($stats['recent_failures'] > config('ops.queue.max_recent_failures')) {
            $problems[] = sprintf(
                '%d شکست در %d دقیقهٔ اخیر (آستانه %d).',
                $stats['recent_failures'],
                config('ops.queue.recent_failure_window_minutes'),
                config('ops.queue.max_recent_failures')
            );
        }

        if ($stats['failed_total'] > config('ops.queue.max_failed_jobs')) {
            $problems[] = sprintf(
                'مجموع failed_jobs برابر %d است (آستانه %d) — نیازمند بررسی و پاک‌سازی.',
                $stats['failed_total'],
                config('ops.queue.max_failed_jobs')
            );
        }

        if ($this->option('json')) {
            $this->line((string) json_encode(
                $stats + ['healthy' => $problems === [], 'problems' => $problems],
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

    /**
     * @return array{pending:int, oldest_pending_age:int|null, failed_total:int, recent_failures:int}
     */
    private function collectStats(): array
    {
        $pending = (int) DB::table('jobs')->count();

        // `jobs.available_at` مهرِ زمانیِ یونیکس است (نه datetime).
        $oldestAvailableAt = DB::table('jobs')->min('available_at');
        $oldestPendingAge = $oldestAvailableAt === null
            ? null
            : max(0, time() - (int) $oldestAvailableAt);

        $failedTotal = (int) DB::table('failed_jobs')->count();

        $recentFailures = (int) DB::table('failed_jobs')
            ->where('failed_at', '>=', now()->subMinutes(
                (int) config('ops.queue.recent_failure_window_minutes')
            ))
            ->count();

        return [
            'pending' => $pending,
            'oldest_pending_age' => $oldestPendingAge,
            'failed_total' => $failedTotal,
            'recent_failures' => $recentFailures,
        ];
    }
}
