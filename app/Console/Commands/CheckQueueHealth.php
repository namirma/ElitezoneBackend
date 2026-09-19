<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ops\QueueHealthService;
use App\Services\SmsService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * پایشِ سلامتِ صف — «worker خوابیده» را از یک خرابیِ خاموش به یک خطای صریح تبدیل می‌کند.
 *
 * همه‌ی listenerهای پروژه ShouldQueue هستند و صف روی درایورِ database است. اگر
 * workerها متوقف شوند هیچ استثنایی پرتاب نمی‌شود: پیامک OTP، اعلان سفارش و ایمیل
 * صرفاً در جدول `jobs` می‌مانند. چون ورود کاربر به پیامک OTP وابسته است، این
 * خرابیِ خاموش عملاً یعنی قطعیِ ورود — بدون هیچ ردی در لاگ.
 *
 * خروجی: exit code 0 سالم، 1 هشدار. مانیتورینگِ بیرونی (cron wrapper/uptime check)
 * می‌تواند روی همین کد alert بگذارد؛ لاگِ `error` هم اگر Sentry DSN ست باشد به
 * error-tracker می‌رود.
 *
 * قواعد و آستانه‌ها در QueueHealthService است تا صفحه‌ی سلامتِ سامانه هم دقیقاً
 * همان‌ها را ببیند.
 */
class CheckQueueHealth extends Command
{
    protected $signature = 'queue:health {--json : خروجی ماشین‌خوان برای مانیتورینگ}';

    protected $description = 'بررسی سلامت صف: سن و حجم jobهای منتظر و تعداد شکست‌ها';

    public function handle(QueueHealthService $queueHealth): int
    {
        $report = $queueHealth->report();

        // تحویلِ پیامک، نه صف — ولی همین‌جا زندگی می‌کند چون *همان* خرابی است که
        // این دستور برایش ساخته شد: کاربر کدِ ورود نمی‌گیرد و هیچ‌جا خطایی ثبت
        // نمی‌شود. یک cron، یک کانالِ هشدار، یک exit code — به‌جای دستورِ دومی که
        // زمان‌بندی‌اش یادِ کسی نمی‌ماند.
        //
        // عمداً **بیرونِ** گاردِ `applicable` است: آن گارد به درایورِ صف مربوط
        // است و اگر روزی QUEUE_CONNECTION روی redis برود، این بررسی نباید با آن
        // بی‌صدا ناپدید شود.
        $smsProblem = SmsService::deliveryProblem();
        $problems = $smsProblem === null ? [] : [$smsProblem];

        $stats = $report['stats'];

        if ($report['applicable']) {
            $problems = array_merge($problems, $report['problems']);
        } elseif (! $this->option('json')) {
            $this->info('بخشِ صف رد شد — queue:health فقط برای درایور database است (درایور فعلی: '.$report['driver'].').');
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
                    'سالم است — منتظر: %d، قدیمی‌ترین: %s، شکست‌ها: %d.',
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
