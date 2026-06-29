<?php

namespace App\Jobs\Order;

use App\Actions\Order\CancelOrderAction;
use App\Models\Order;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class CheckReservationExpiryJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * این job از طریق Kernel::schedule() به صورت دوره‌ای اجرا می‌شود.
     * در صورت شکست، یک بار مجدداً تلاش می‌کند (بعد از ۶۰ ثانیه).
     * اگر دوباره هم شکست خورد، در failed_jobs ثبت می‌شود تا بررسی شود.
     */
    public int $tries = 2;

    public array $backoff = [60];

    public function handle(CancelOrderAction $cancelAction): void
    {
        // cursor() streams one row at a time — avoids loading all expired orders
        // into memory at once, which matters when many reservations expire together.
        $expiredOrders = Order::query()
            ->whereIn('status', [
                Order::STATUS_PENDING,
                Order::STATUS_RESERVED,
                Order::STATUS_RESERVED_EXTENDED,
            ])
            ->whereNotNull(Order::RESERVATION_EXPIRES_AT)
            ->where(Order::RESERVATION_EXPIRES_AT, '<', Carbon::now())
            ->cursor();

        foreach ($expiredOrders as $order) {
            try {
                $cancelAction->execute(
                    $order,
                    reason: 'انقضای خودکار رزرو/سفارش معلق',
                    refundWallet: true,
                    isSystemCron: true
                );
                Log::channel('orders')->info("سفارش {$order->id} (وضعیت: {$order->status}) به دلیل انقضا لغو شد.");
            } catch (\Throwable $e) {
                Log::channel('orders')->error("خطا در لغو خودکار سفارش {$order->id}: {$e->getMessage()}");
            }
        }
    }
}
