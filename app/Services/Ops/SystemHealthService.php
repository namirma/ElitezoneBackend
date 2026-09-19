<?php

declare(strict_types=1);

namespace App\Services\Ops;

use App\Models\Order;
use App\Services\Financial\LedgerReconciler;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * یک صفحه که می‌گوید «سامانه سالم است» یا نیست.
 *
 * چهار خرابیِ خاموشِ این پروژه را کنار هم می‌گذارد — چهارتایی که هیچ‌کدام خودشان
 * را اعلام نمی‌کنند و هر کدام جداگانه باید پرسیده شوند:
 *
 *   ۱. worker خوابیده  → پیامک OTP نمی‌رود، ورود عملاً قطع است
 *   ۲. پشتیبان کهنه    → روزِ بازگردانی معلوم می‌شود که چیزی نیست
 *   ۳. دفتر و موجودی   → عددِ غلط در کیف پول، بدونِ هیچ خطایی
 *   ۴. سفارشِ گیرکرده  → در یک وضعیتِ میانی مانده و کسی متوجه نشده
 *
 * `healthy` فقط وقتی true است که هر چهار بخش سالم باشند؛ یک صفحه‌ی سلامت که در
 * حضورِ مشکل هم «سبز» نشان دهد بدتر از نبودنش است.
 *
 * فقط می‌خواند — هیچ اصلاحی انجام نمی‌دهد.
 */
final class SystemHealthService
{
    public function __construct(
        private readonly QueueHealthService $queue,
        private readonly BackupHealthService $backup,
        private readonly LedgerReconciler $ledger,
    ) {}

    /**
     * @return array{
     *     healthy: bool,
     *     checked_at: string,
     *     sections: array<string, array<string, mixed>>,
     *     problems: array<int, string>
     * }
     */
    public function report(): array
    {
        $sections = [
            'queue' => $this->queue->report(),
            'backup' => $this->backup->report(),
            'ledger' => $this->ledgerSection(),
            'orders' => $this->stuckOrdersSection(),
        ];

        $problems = [];
        foreach ($sections as $section) {
            foreach ($section['problems'] as $problem) {
                $problems[] = $problem;
            }
        }

        return [
            'healthy' => $problems === [],
            'checked_at' => now()->toDateTimeString(),
            'sections' => $sections,
            'problems' => $problems,
        ];
    }

    /**
     * خلاصه‌ی تطبیقِ دفتر — نه خودِ ردیف‌ها.
     *
     * فهرستِ کاملِ مغایرت‌ها می‌تواند بلند باشد و اینجا جایش نیست؛ برای بررسی
     * `financial:doctor` هست. این صفحه فقط باید بگوید «هست یا نیست».
     *
     * نتیجه کش می‌شود: تطبیق چهار کوئریِ تجمیعی روی کلِ `wallet_transactions`
     * می‌زند و این اندپوینت — برخلافِ cronِ شبانه — می‌تواند پشتِ‌هم صدا زده شود.
     * `computed_at` همراه پاسخ می‌آید تا کهنگی پنهان نماند.
     *
     * @return array<string, mixed>
     */
    private function ledgerSection(): array
    {
        $minutes = (int) config('ops.health.ledger_cache_minutes');

        if ($minutes <= 0) {
            return $this->computeLedgerSection();
        }

        return Cache::remember(
            'ops:health:ledger',
            now()->addMinutes($minutes),
            fn () => $this->computeLedgerSection(),
        );
    }

    /** @return array<string, mixed> */
    private function computeLedgerSection(): array
    {
        $report = $this->ledger->report();

        $counts = [
            'drifted_wallets' => count($report['wallets']['drifted']),
            'unbalanced_orders' => count($report['unbalanced_orders']),
            'orphan_holds' => count($report['orphan_holds']),
            'mispriced_orders' => count($report['mispriced_orders']),
            'unknown_combinations' => count($report['unknown_combinations']),
        ];

        $problems = [];

        if ($counts['drifted_wallets'] > 0) {
            $problems[] = sprintf(
                '%d کیف پول با دفتر نمی‌خواند — `php artisan financial:doctor` را اجرا کنید.',
                $counts['drifted_wallets'],
            );
        }

        if ($counts['unbalanced_orders'] > 0) {
            $problems[] = sprintf('%d سفارش جمعِ جبریِ غیرصفر دارد (پول خلق یا نابود شده).', $counts['unbalanced_orders']);
        }

        if ($counts['orphan_holds'] > 0) {
            $problems[] = sprintf('%d سفارشِ تمام‌شده هولدِ آزادنشده دارد.', $counts['orphan_holds']);
        }

        if ($counts['mispriced_orders'] > 0) {
            $problems[] = sprintf(
                '%d سفارش مبنای قیمتی دارد که با ارزشِ ردیف‌هایش نمی‌خواند (صورتحسابِ غلط).',
                $counts['mispriced_orders'],
            );
        }

        if ($counts['unknown_combinations'] > 0) {
            $problems[] = sprintf('%d ترکیبِ مدل‌نشده در دفتر هست — نتیجه‌ی تطبیق قابل اتکا نیست.', $counts['unknown_combinations']);
        }

        return $counts + [
            'computed_at' => now()->toDateTimeString(),
            'healthy' => $problems === [],
            'problems' => $problems,
        ];
    }

    /**
     * سفارش‌هایی که در یک وضعیتِ میانی مانده‌اند.
     *
     * وضعیت‌های نهایی (تکمیل/لغو/ناموفق/تحویل) عمداً بیرون‌اند: ماندن در آن‌ها
     * طبیعی است. `pending` هم بیرون است چون لغوِ خودکارِ رزروهای منقضی job خودش
     * را دارد و گزارشش اینجا فقط نویز می‌شود.
     *
     * @return array{threshold_hours:int, count:int, by_status:array<string, int>, sample:array<int, array<string, mixed>>, healthy:bool, problems:array<int, string>}
     */
    private function stuckOrdersSection(): array
    {
        $hours = (int) config('ops.orders.stuck_after_hours');
        $cutoff = now()->subHours($hours);

        // `returned_to_origin` عمداً اینجا نیست. «گیرکرده» یعنی سفارشی که باید
        // حرکت کند و نمی‌کند؛ ولی عودت‌خورده منتظرِ یک چرخه‌ی تأییدِ خسارت است که
        // می‌تواند روزها طول بکشد و هیچ‌کس هم گیر نکرده. با بودنش، هر عودت پس از
        // `ops.orders.stuck_after_hours` یک هشدارِ دائمی می‌ساخت که هیچ اقدامی
        // خاموشش نمی‌کرد — و هشداری که همیشه روشن است، هشدار نیست.
        $intermediate = [
            Order::STATUS_PROCESSING,
            Order::STATUS_AWAITING_WEIGHT_CORRECTION,
            Order::STATUS_SHIPPED,
            Order::STATUS_PARTIALLY_DELIVERED,
        ];

        $base = Order::query()
            ->whereIn(Order::STATUS, $intermediate)
            ->where(Order::UPDATED_AT, '<', $cutoff);

        $count = (clone $base)->count();

        // نمونه به‌صورت projection خوانده می‌شود نه مدلِ کامل: این فقط چند ستون
        // برای نمایش است و هیچ رفتاری از مدل لازم ندارد.
        $sample = (clone $base)
            ->orderBy(Order::UPDATED_AT)
            ->limit((int) config('ops.orders.stuck_sample_size'))
            ->get([Order::ID, Order::ORDER_NUMBER, Order::STATUS, Order::UPDATED_AT])
            ->map(fn ($order) => [
                'id' => (int) $order->getAttribute(Order::ID),
                'order_number' => $order->getAttribute(Order::ORDER_NUMBER),
                'status' => (string) $order->getAttribute(Order::STATUS),
                // ترتیبِ عملوندها عمدی است: Carbon 3 (لاراول ۱۳) عددِ **علامت‌دار**
                // برمی‌گرداند و Carbon 2 قدرمطلق. با `now()->diffInHours($past)`
                // روی این شاخه هر سفارشِ گیرکرده «−۲۴۰ ساعت» گزارش می‌شد. از گذشته
                // به now هر دو نسخه مثبت می‌دهند.
                'idle_hours' => (int) $order->getAttribute(Order::UPDATED_AT)->diffInHours(now()),
            ])
            ->all();

        // تفکیکِ وضعیت‌ها بیشتر از عددِ کل کمک می‌کند: انباشت در یک وضعیتِ خاص
        // معمولاً یعنی یک مرحله‌ی مشخص از فرآیند متوقف شده، نه اینکه همه‌چیز کند است.
        $byStatus = (clone $base)
            ->select(Order::STATUS, DB::raw('COUNT(*) as total'))
            ->groupBy(Order::STATUS)
            ->pluck('total', Order::STATUS)
            ->all();

        return [
            'threshold_hours' => $hours,
            'count' => $count,
            'by_status' => $byStatus,
            'sample' => $sample,
            'healthy' => $count === 0,
            'problems' => $count === 0 ? [] : [
                sprintf('%d سفارش بیش از %d ساعت است در وضعیتِ میانی مانده‌اند.', $count, $hours),
            ],
        ];
    }
}
