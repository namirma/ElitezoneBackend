<?php

namespace App\Jobs;

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

    public function handle(CancelOrderAction $cancelAction): void
    {
        $expiredOrders = Order::query()
            ->whereIn('status', [
                Order::STATUS_PENDING,
                Order::STATUS_RESERVED,
                Order::STATUS_RESERVED_EXTENDED,
            ])
            ->whereNotNull(Order::RESERVATION_EXPIRES_AT)
            ->where(Order::RESERVATION_EXPIRES_AT, '<', Carbon::now())
            ->get();

        foreach ($expiredOrders as $order) {
            try {
                $cancelAction->execute(
                    $order,
                    reason: 'انقضای خودکار رزرو/سفارش معلق',
                    refundWallet: true,
                    isSystemCron: true
                );
                Log::info("سفارش {$order->id} (وضعیت: {$order->status}) به دلیل انقضا لغو شد.");
            } catch (\Throwable $e) {
                Log::error("خطا در لغو خودکار سفارش {$order->id}: {$e->getMessage()}");
            }
        }
    }
}
