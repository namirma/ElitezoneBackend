<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Order;
use App\Services\Order\OrderTimelineService;
use Illuminate\Console\Command;

/**
 * نمایشِ ترمینالیِ روایتِ سفارش.
 *
 * همان داده‌ی اندپوینتِ ادمین، ولی بدون نیاز به توکن و پنل — وقتی کسی نیمه‌شب دنبالِ
 * «این سفارش کجا گیر کرد» می‌گردد، `ssh` و یک دستور سریع‌ترین مسیر است.
 *
 * ورودی می‌تواند شناسه یا شماره‌ی سفارش باشد؛ پشتیبانی از هر دو عمدی است چون آنچه
 * در تیکتِ پشتیبانی نوشته می‌شود همیشه شماره‌ی سفارش است نه id.
 */
class ShowOrderStory extends Command
{
    protected $signature = 'order:story
        {order : شناسه یا شماره‌ی سفارش}
        {--json : خروجی ماشین‌خوان}';

    protected $description = 'نمایش خط زمانی کامل یک سفارش: چرخه عمر، وضعیت‌ها، حرکت‌های پول و اسناد';

    public function handle(OrderTimelineService $timeline): int
    {
        $order = $this->resolveOrder((string) $this->argument('order'));

        if (! $order) {
            $this->error('سفارشی با این شناسه یا شماره پیدا نشد.');

            return self::FAILURE;
        }

        $story = $timeline->build($order);

        if ($this->option('json')) {
            $this->line((string) json_encode($story, JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $this->renderHeader($story['order'], $story['span']);

        if ($story['events'] === []) {
            $this->warn('هیچ رویدادی برای این سفارش ثبت نشده.');

            return self::SUCCESS;
        }

        $this->table(
            ['زمان', 'فاصله', 'مسیر', 'رویداد', 'مبلغ (ریال)', 'عامل'],
            array_map(fn (array $event) => [
                $event['at_jalali'],
                $event['gap'] ?? '—',
                $this->trackLabel($event['track']),
                $this->wrap($event['title'], $event['detail']),
                $event['amount'] === null ? '—' : number_format((float) $event['amount']),
                $event['actor'] ?? 'سیستم',
            ], $story['events']),
        );

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $summary
     * @param  array<string, mixed>  $span
     */
    private function renderHeader(array $summary, array $span): void
    {
        $this->newLine();
        $this->info(sprintf(
            'سفارش %s (#%d) — %s',
            $summary['order_number'] ?? '—',
            $summary['id'],
            $summary['status_label'],
        ));

        if ($span['from'] !== null) {
            $this->line(sprintf(
                '  از %s تا %s (%d روز)',
                $span['from'],
                $span['to'],
                $span['days'],
            ));
        }

        $this->newLine();
    }

    private function wrap(string $title, ?string $detail): string
    {
        return $detail === null || $detail === ''
            ? $title
            : $title."\n  ".$detail;
    }

    private function trackLabel(string $track): string
    {
        return match ($track) {
            'lifecycle' => 'چرخه',
            'status' => 'وضعیت',
            'money' => 'پول',
            'document' => 'سند',
            default => $track,
        };
    }

    private function resolveOrder(string $needle): ?Order
    {
        // شماره‌ی سفارش اول بررسی می‌شود: یک شماره‌ی کاملاً عددی نباید به‌اشتباه
        // به‌عنوان id تفسیر شود و سفارشِ دیگری را برگرداند.
        $order = Order::query()->where(Order::ORDER_NUMBER, $needle)->first();

        if ($order === null && ctype_digit($needle)) {
            $order = Order::query()->find((int) $needle);
        }

        return $order instanceof Order ? $order : null;
    }
}
