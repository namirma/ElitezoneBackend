<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Financial\LedgerReconciler;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * پزشکِ دفترِ مالی — «موجودیِ بی‌صدا غلط» را به یک خطای صریح تبدیل می‌کند.
 *
 * خواهرِ `queue:health` است: هر دو یک خرابیِ خاموش را قابل دیدن می‌کنند. تفاوت در
 * این است که خرابیِ صف را کاربر بالاخره گزارش می‌کند («کد نیامد»)، ولی یک موجودیِ
 * غلط ممکن است سال‌ها کشف نشود — و هر روزی که بگذرد، ردیابیِ منشأش سخت‌تر می‌شود.
 *
 * exit code صفر = سالم، ۱ = مغایرت. مثل queue:health لاگِ سطح error می‌زند تا اگر
 * SENTRY_LARAVEL_DSN ست باشد رویدادِ Sentry بسازد.
 *
 * فقط می‌خواند — هیچ‌چیز را اصلاح نمی‌کند. اصلاحِ خودکارِ یک مغایرتِ مالی بدونِ
 * فهمیدنِ علتش، شواهد را پاک می‌کند و بدترین کارِ ممکن است.
 */
class ReconcileLedger extends Command
{
    protected $signature = 'financial:doctor
        {--json : خروجی ماشین‌خوان برای مانیتورینگ}
        {--order= : بررسی فقط یک سفارش}
        {--user= : بررسی فقط کیف پولِ یک کاربر}';

    protected $description = 'تطبیق دفتر مالی با موجودی کیف‌ها، بررسی صفر شدن تسویه‌ی سفارش‌ها و کشف هولدهای آزادنشده';

    public function handle(LedgerReconciler $reconciler): int
    {
        $report = $reconciler->report(
            userId: $this->intOption('user'),
            orderId: $this->intOption('order'),
        );

        if ($this->option('json')) {
            $this->line((string) json_encode($report, JSON_UNESCAPED_UNICODE));

            return $report['healthy'] ? self::SUCCESS : self::FAILURE;
        }

        if ($report['healthy']) {
            $this->info(sprintf(
                'دفتر و موجودی‌ها می‌خوانند — %d کیف بررسی شد، هیچ مغایرتی نیست.',
                $report['wallets']['checked'],
            ));

            return self::SUCCESS;
        }

        $this->renderTables($report);

        // سطح error (نه warning): یک مغایرتِ مالی یعنی همین حالا عددِ غلطی به کاربر
        // نشان داده می‌شود یا پولی در جای اشتباه نشسته.
        Log::channel('financial')->error('Ledger reconciliation failed', [
            'drifted_wallets' => count($report['wallets']['drifted']),
            'unbalanced_orders' => count($report['unbalanced_orders']),
            'orphan_holds' => count($report['orphan_holds']),
            'mispriced_orders' => count($report['mispriced_orders']),
            'unknown_combinations' => $report['unknown_combinations'],
            'problems' => $report['problems'],
        ]);

        return self::FAILURE;
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function renderTables(array $report): void
    {
        if ($report['unknown_combinations'] !== []) {
            // اول چاپ می‌شود چون اعتبارِ بقیه‌ی جدول‌ها را زیر سؤال می‌برد.
            $this->error('ترکیب‌های مدل‌نشده در دفتر — نتیجه‌ی زیر تا رفعِ این‌ها قابل اتکا نیست:');
            $this->table(
                ['نوع', 'وضعیت', 'تعداد ردیف'],
                array_map(fn ($row) => [$row['type'], $row['status'], $row['rows']], $report['unknown_combinations']),
            );
        }

        if ($report['wallets']['drifted'] !== []) {
            $this->error('کیف‌هایی که موجودی‌شان با دفتر نمی‌خواند:');
            $this->table(
                ['کیف', 'کاربر', 'موجودیِ ثبت‌شده', 'موجودیِ دفتر', 'اختلاف', 'هولدِ ثبت‌شده', 'هولدِ دفتر', 'اختلاف'],
                array_map(fn ($row) => [
                    $row['wallet_id'], $row['user_id'],
                    $row['stored_balance'], $row['expected_balance'], $row['balance_diff'],
                    $row['stored_hold'], $row['expected_hold'], $row['hold_diff'],
                ], $report['wallets']['drifted']),
            );
        }

        if ($report['unbalanced_orders'] !== []) {
            $this->error('سفارش‌هایی که جمعِ جبریِ تراکنش‌هایشان صفر نیست:');
            $this->table(
                ['سفارش', 'شماره', 'جمعِ جبری'],
                array_map(fn ($row) => [$row['order_id'], $row['order_number'] ?? '—', $row['net']], $report['unbalanced_orders']),
            );
        }

        if ($report['mispriced_orders'] !== []) {
            $this->error('سفارش‌هایی که مبنای قیمتشان با ارزشِ ردیف‌ها نمی‌خواند:');
            $this->table(
                ['سفارش', 'شماره', 'ارزشِ ردیف‌ها', 'مبنای محاسبه', 'اختلاف', 'اسنپ‌شات دارد؟'],
                array_map(fn ($row) => [
                    $row['order_id'], $row['order_number'] ?? '—',
                    $row['goods_value'], $row['charged_basis'], $row['difference'],
                    $row['has_snapshot'] ? 'بله' : 'خیر',
                ], $report['mispriced_orders']),
            );
        }

        if ($report['orphan_holds'] !== []) {
            $this->error('سفارش‌های تمام‌شده با هولدِ آزادنشده:');
            $this->table(
                ['سفارش', 'شماره', 'وضعیت', 'هولدِ گیرکرده', 'سن (روز)'],
                array_map(fn ($row) => [
                    $row['order_id'], $row['order_number'] ?? '—',
                    $row['order_status'], $row['stuck_hold'], $row['age_days'],
                ], $report['orphan_holds']),
            );
        }
    }

    private function intOption(string $name): ?int
    {
        $value = $this->option($name);

        return $value === null || $value === '' ? null : (int) $value;
    }
}
