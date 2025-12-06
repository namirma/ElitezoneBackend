<?php

namespace App\Console\Commands;

use App\Models\Order;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Exception;

class CancelExpiredOrders extends Command
{
    /**
     * نام و امضای دستور کنسولی
     */
    protected $signature = 'orders:cancel-expired';

    /**
     * توضیحات دستور
     */
    protected $description = 'Cancel pending or reserved orders that have passed their expiration time.';

    /**
     * دلیل لغو سفارش (ثابت)
     */
    private const CANCEL_REASON = 'این سفارش به دلیل گذشتن زمان اعتبار یا عدم تکمیل پرداخت، توسط سیستم لغو شد.';

    /**
     * اجرای دستور
     */
    public function handle()
    {
        $this->info('Searching for expired orders...');

        $query = $this->getExpiredOrdersQuery();
        $totalOrders = $query->count();

        if ($totalOrders === 0) {
            $this->warn('No expired orders found.');
            return 0;
        }

        $this->info("Found {$totalOrders} expired orders. Processing...");

        $bar = $this->output->createProgressBar($totalOrders);
        $bar->start();

        $count = 0;

        // پردازش دسته‌ای (Chunk) برای بهینه‌سازی حافظه
        $query->chunkById(100, function ($orders) use ($bar, &$count) {
            foreach ($orders as $order) {
                try {
                    // فراخوانی متد پردازش لغو
                    $this->processCancellation($order);
                    $count++;
                } catch (Exception $e) {
                    Log::error("Failed to cancel order #{$order->id}: " . $e->getMessage());
                }
                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine();
        $this->info("Successfully cancelled {$count} orders.");

        return 0;
    }

    /**
     * کوئری دریافت سفارش‌های منقضی شده
     */
    private function getExpiredOrdersQuery()
    {
        return Order::whereIn(Order::STATUS, [
                Order::STATUS_PENDING,
                Order::STATUS_RESERVED_EXTENDED,
            ])
            ->where(Order::RESERVATION_EXPIRES_AT, '<=', Carbon::now());
    }

    /**
     * منطق اصلی لغو سفارش (تراکنش دیتابیس)
     */
    private function processCancellation(Order $order): void
    {
        DB::transaction(function () use ($order) {
            // ۱. بازگرداندن موجودی کالا به انبار
            $order->restoreStock();

            // ۲. بازگرداندن وجه به کیف پول (اگر پرداختی داشته)
            // (این متد خودش وضعیت پرداخت را به REFUNDED تغییر می‌دهد)
            $order->refundToWallet();

            // ۳. تعیین وضعیت نهایی پرداخت
            // اگر متد refundToWallet اجرا شده باشد، پیمنت استاتوس آپدیت شده است.
            // اما اگر سفارشی پولی نداده بود (Pending)، باید وضعیت پرداختش هم کنسل شود.
            $paymentStatus = $order->fresh()->{Order::PAYMENT_STATUS};

            if ($paymentStatus === Order::PAYMENT_PENDING) {
                $paymentStatus = Order::STATUS_CANCELLED;
            }

            // ۴. آپدیت نهایی سفارش
            $order->update([
                Order::STATUS         => Order::STATUS_CANCELLED,
                Order::PAYMENT_STATUS => $paymentStatus,
                Order::CANCEL_REASON  => self::CANCEL_REASON // استفاده صحیح از ثابت
            ]);
        });

        Log::info("Order #{$order->id} automatically cancelled due to expiry.");
    }
}
