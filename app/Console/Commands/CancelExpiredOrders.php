<?php

namespace App\Console\Commands;

use App\Actions\Order\CancelOrderAction;
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
     * دلیل لغو سیستمی (ثابت)
     */
    private const CANCEL_REASON = 'لغو خودکار به دلیل انقضای مهلت پرداخت';

    /**
     * اجرای دستور
     * * ما کلاس اکشن را اینجا تزریق می‌کنیم تا لاراول خودش آن را بسازد
     */
    public function handle(CancelOrderAction $cancelAction)
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

        // پردازش دسته‌ای (Chunk) برای جلوگیری از پر شدن حافظه رم در تعداد بالا
        $query->chunkById(100, function ($orders) use ($bar, &$count, $cancelAction) {
            foreach ($orders as $order) {
                try {
                    // این لغو توسط کرون‌جاب سیستم انجام می‌شود
                    $cancelAction->execute($order, self::CANCEL_REASON, refundWallet: true, isSystemCron: true);

                    $count++;
                } catch (Exception $e) {
                    // ثبت خطا بدون متوقف کردن کل فرآیند
                    Log::error("Failed to auto-cancel order #{$order->id}: " . $e->getMessage());
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
     * * شامل سفارش‌های "در انتظار" و "رزرو تمدید شده" که زمانشان گذشته است
     */
    private function getExpiredOrdersQuery()
    {
        return Order::whereIn(Order::STATUS, [
            Order::STATUS_PENDING,
            Order::STATUS_RESERVED,
            Order::STATUS_RESERVED_EXTENDED,
        ])
            ->where(Order::RESERVATION_EXPIRES_AT, '<=', Carbon::now());
    }
}
