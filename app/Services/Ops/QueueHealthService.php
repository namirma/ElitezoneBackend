<?php

declare(strict_types=1);

namespace App\Services\Ops;

use Illuminate\Support\Facades\DB;

/**
 * سنجشِ سلامتِ صف — منبعِ واحدِ هم دستورِ `queue:health` و هم صفحه‌ی سلامتِ سامانه.
 *
 * منطق از خودِ دستور بیرون کشیده شد تا آستانه‌ها و متن‌های هشدار در دو جا تکرار
 * نشوند؛ دو نسخه‌ی همین قواعد دیر یا زود از هم واگرا می‌شدند و آن‌وقت پنل و ترمینال
 * دو حرفِ متفاوت می‌زدند درباره‌ی یک سیستم.
 */
final class QueueHealthService
{
    /**
     * @return array{
     *     applicable: bool,
     *     driver: string,
     *     stats: array{pending:int, oldest_pending_age:int|null, failed_total:int, recent_failures:int},
     *     healthy: bool,
     *     problems: array<int, string>
     * }
     */
    public function report(): array
    {
        // فقط برای درایورِ database معنا دارد؛ روی redis/sqs متریک‌ها جای دیگری‌اند.
        if (config('queue.default') !== 'database') {
            return [
                'applicable' => false,
                'driver' => (string) config('queue.default'),
                'stats' => ['pending' => 0, 'oldest_pending_age' => null, 'failed_total' => 0, 'recent_failures' => 0],
                'healthy' => true,
                'problems' => [],
            ];
        }

        $stats = $this->stats();
        $problems = [];

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
                '%d شکست در %d دقیقه‌ی اخیر (آستانه %d).',
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

        return [
            'applicable' => true,
            'driver' => 'database',
            'stats' => $stats,
            'healthy' => $problems === [],
            'problems' => $problems,
        ];
    }

    /**
     * @return array{pending:int, oldest_pending_age:int|null, failed_total:int, recent_failures:int}
     */
    private function stats(): array
    {
        $pending = (int) DB::table('jobs')->count();

        // `jobs.available_at` مهرِ زمانیِ یونیکس است (نه datetime).
        $oldestAvailableAt = DB::table('jobs')->min('available_at');
        $oldestPendingAge = $oldestAvailableAt === null
            ? null
            : max(0, time() - (int) $oldestAvailableAt);

        return [
            'pending' => $pending,
            'oldest_pending_age' => $oldestPendingAge,
            'failed_total' => (int) DB::table('failed_jobs')->count(),
            'recent_failures' => (int) DB::table('failed_jobs')
                ->where('failed_at', '>=', now()->subMinutes(
                    (int) config('ops.queue.recent_failure_window_minutes')
                ))
                ->count(),
        ];
    }
}
