<?php

namespace App\Console\Commands;

use App\Models\Order;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

class CancelExpiredOrders extends Command
{
    /**
     * امضای دستور شما در ترمینال
     */
    protected $signature = 'orders:cancel-expired';

    /**
     * توضیحات دستور
     */
    protected $description = 'Cancel pending or reserved_extended orders that have passed their reservation expiry time.';

    /**
     * اجرای منطق دستور
     */
    public function handle()
    {
        $this->info('Finding and cancelling expired orders...');
        $now = Carbon::now();

        // پیامی که شما درخواست کردید در سفارش ذخیره شود
        $cancelReason = 'این سفارش به دلیل گذشتن زمان اعتبار فاکتور یا عدم پرداخت یا عدم تکمیل پرداخت توسط خریدار لغو شده است.';

        // وضعیت‌هایی که قابل انقضا هستند (رزرو ۲ ساعته و رزرو تمدید شده ۲۴ ساعته)
        $expirableStatuses = [
            Order::STATUS_PENDING,
            Order::STATUS_RESERVED_EXTENDED,
        ];

        // کوئری برای یافتن سفارش‌های منقضی شده
        $query = Order::whereIn(Order::STATUS, $expirableStatuses)
                        ->where(Order::RESERVATION_EXPIRES_AT, '<=', $now);

        $count = 0;

        // استفاده از chunkById برای پردازش دسته‌ای سفارش‌ها (جلوگیری از فشار به دیتابیس)
        $query->chunkById(100, function ($orders) use ($cancelReason, &$count) {
            foreach ($orders as $order) {

                // تعیین وضعیت پرداخت بر اساس منطق OrderController@cancel
                $newPaymentStatus = $order->{Order::PAYMENT_STATUS};

                if ($order->{Order::PAYMENT_STATUS} === Order::PAYMENT_PENDING) {
                    // اگر هیچ پرداختی نداشته، لغو می‌شود
                    $newPaymentStatus = Order::STATUS_CANCELLED; // یا PAYMENT_FAILED
                }
                // اگر رزرو تمدید شده بود (STATUS_RESERVED_EXTENDED)، یعنی پرداخت اولیه داشته
                elseif ($order->{Order::STATUS} === Order::STATUS_RESERVED_EXTENDED) {
                    // باید مبلغ پیش‌پرداخت بازگردانده شود
                    $newPaymentStatus = Order::PAYMENT_REFUND_PENDING;
                }

                // آپدیت سفارش
                $order->update([
                    Order::STATUS => Order::STATUS_CANCELLED,
                    Order::PAYMENT_STATUS => $newPaymentStatus,
                    Order::CANCEL_REASON => $cancelReason
                ]);

                Log::info("Order #{$order->id} automatically cancelled due to reservation expiry.");
                $count++;
            }
        });

        if ($count > 0) {
            $this->info("Successfully cancelled {$count} orders.");
        } else {
            $this->info('No expired orders found.');
        }

        return 0;
    }
}
