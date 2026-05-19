<?php

namespace App\Observers;

use App\Models\Order;
use App\Models\OrderStatusHistory;
use Illuminate\Support\Facades\Auth;

class OrderObserver
{
    /**
     * رویداد اول: وقتی سفارش جدید ایجاد می‌شود (Created)
     */
    public function created(Order $order): void
    {
        // ثبت نقطه شروع فرآیند (وضعیت اولیه)
        OrderStatusHistory::create([
            OrderStatusHistory::ORDER_ID => $order->id,
            OrderStatusHistory::FROM_STATUS => null,
            OrderStatusHistory::TO_STATUS => $order->status ?? Order::STATUS_PENDING,
            OrderStatusHistory::USER_ID => Auth::id(),
            OrderStatusHistory::DURATION_MINUTES => 0,
            OrderStatusHistory::CREATED_AT => now(),
        ]);
    }

    /**
     * رویداد دوم: وقتی سفارش آپدیت می‌شود (Updated)
     */
    public function updated(Order $order): void
    {
        if ($order->isDirty(Order::STATUS)) {

            $oldStatus = $order->getOriginal(Order::STATUS);
            $newStatus = $order->{Order::STATUS};

            /* -----------------------------------------------------
             * محاسبه زمان توقف (Duration Calculation)
             * ----------------------------------------------------- */

            $lastHistory = OrderStatusHistory::where(OrderStatusHistory::ORDER_ID, $order->id)
                ->latest(OrderStatusHistory::CREATED_AT)
                ->first();

            $duration = 0;
            if ($lastHistory) {
                $duration = now()->diffInMinutes($lastHistory->{OrderStatusHistory::CREATED_AT});
            }

            /* -----------------------------------------------------
             * ثبت رکورد جدید در تاریخچه
             * ----------------------------------------------------- */

            OrderStatusHistory::create([
                OrderStatusHistory::ORDER_ID => $order->id,
                OrderStatusHistory::FROM_STATUS => $oldStatus,
                OrderStatusHistory::TO_STATUS => $newStatus,
                OrderStatusHistory::USER_ID => Auth::id(),
                OrderStatusHistory::DURATION_MINUTES => $duration,
                OrderStatusHistory::CREATED_AT => now(),
            ]);
        }
    }
}
