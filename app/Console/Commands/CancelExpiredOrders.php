<?php

namespace App\Console\Commands;

use App\Models\Order;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
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
     * دلیل لغو سفارش
     */
    private const CANCEL_REASON = '.این سفارش به دلیل گذشتن زمان اعتبار یا عدم تکمیل پرداخت، توسط سیستم لغو شد';

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

        // پردازش دسته‌ای برای مدیریت حافظه
        $query->chunkById(100, function ($orders) use ($bar, &$count) {
            foreach ($orders as $order) {
                try {
                    $this->cancelOrder($order);
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
     * دریافت کوئری سفارش‌های منقضی شده
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
     * منطق لغو کردن یک سفارش تکی
     */
    private function cancelOrder(Order $order): void
    {
        $newPaymentStatus = $this->resolvePaymentStatus($order);

        $order->update([
            Order::STATUS         => Order::STATUS_CANCELLED,
            Order::PAYMENT_STATUS => $newPaymentStatus,
            Order::CANCEL_REASON  => self::CANCEL_REASON
        ]);

        Log::info("Order #{$order->id} automatically cancelled due to expiry.");
    }

    /**
     * تعیین وضعیت جدید پرداخت بر اساس وضعیت فعلی سفارش
     */
    private function resolvePaymentStatus(Order $order): string
    {
        // اگر رزرو تمدید شده باشد، یعنی قبلاً بخشی از مبلغ پرداخت شده است (نیاز به عودت)
        if ($order->{Order::STATUS} === Order::STATUS_RESERVED_EXTENDED) {
            return Order::PAYMENT_REFUND_PENDING;
        }

        // در غیر این صورت، اگر پرداخت هنوز انجام نشده، وضعیت به لغو شده تغییر می‌کند
        if ($order->{Order::PAYMENT_STATUS} === Order::PAYMENT_PENDING) {
            return Order::STATUS_CANCELLED;
        }

        // اگر هیچکدام نبود، وضعیت فعلی پرداخت را حفظ کن
        return $order->{Order::PAYMENT_STATUS};
    }
}
