<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Order\RequestOverweightApprovalAction;
use App\Models\Order;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * درخواست تأیید اضافه‌باری که تا `overweight_response_hours` بی‌پاسخ مانده.
 *
 * بند ۳.۷: «اگر خریدار رد کند، تأمین‌کننده رد کند، **یا مهلت بگذرد** ⇒ محموله
 * باطل و سفارش به `processing` بازمی‌گردد.» انقضا دقیقاً همان مسیرِ رد است، پس
 * همان `reject()` را صدا می‌زند و منطق باطل‌کردن دوجا تکرار نمی‌شود.
 *
 * ⚠️ **هیچ لِگ مالی اینجا نیست** — در این شاخه اصلاً تعدیلی اجرا نشده بود.
 *
 * کران و نه job زمان‌بندی‌شده، به همان دلیلِ `shipments:expire-delivery`: مهلت
 * قابل‌تغییر است و job با delay را نمی‌شود لغو کرد.
 */
class ExpireOverweightApprovals extends Command
{
    protected $signature = 'orders:expire-overweight';

    protected $description = 'درخواست‌های تأیید اضافه‌بارِ بی‌پاسخ را باطل و سفارش را به آماده‌سازی بازمی‌گرداند';

    public function handle(RequestOverweightApprovalAction $action): int
    {
        $expired = 0;

        Order::query()
            ->where(Order::STATUS, Order::STATUS_AWAITING_WEIGHT_CORRECTION)
            ->where(Order::OVERWEIGHT_PENDING_AMOUNT, '>', 0)
            ->whereNotNull(Order::OVERWEIGHT_DEADLINE)
            ->where(Order::OVERWEIGHT_DEADLINE, '<=', now())
            ->chunkById(100, function ($orders) use ($action, &$expired) {
                foreach ($orders as $order) {
                    /** @var Order $order */
                    try {
                        $action->reject($order, 'deadline', 'مهلت پاسخ به درخواست اضافه‌بار گذشت.');
                        $expired++;
                    } catch (\Throwable $e) {
                        Log::channel('financial')->error(
                            "orders:expire-overweight failed for order {$order->id}: ".$e->getMessage()
                        );
                    }
                }
            });

        $this->info("{$expired} overweight request(s) expired.");

        return self::SUCCESS;
    }
}
