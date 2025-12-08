<?php

namespace App\Actions\Order;

use App\Models\Order;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CancelOrderAction
{
    /**
     * اجرای عملیات لغو سفارش
     *
     * @param Order $order
     * @param string|null $reason
     * @param bool $refundWallet
     * @return Order
     */
    public function execute(Order $order, ?string $reason = 'لغو سیستمی', bool $refundWallet = true): Order
    {
        return DB::transaction(function () use ($order, $reason, $refundWallet) {

            // ۱. بازگرداندن موجودی کالا به انبار
            $order->restoreStock();

            // ۲. بازگرداندن وجه به کیف پول
            if ($refundWallet) {
                $order->refundToWallet();
            }

            // ۳. تعیین وضعیت جدید پرداخت
            $newPaymentStatus = $this->resolvePaymentStatus($order);

            // ۴. آپدیت نهایی سفارش
            $order->update([
                Order::STATUS         => Order::STATUS_CANCELLED,
                Order::PAYMENT_STATUS => $newPaymentStatus,
                Order::CANCEL_REASON  => $reason
            ]);

            Log::info("Order #{$order->id} cancelled using Action class.");

            return $order;
        });
    }

    /**
     * منطق تشخیص وضعیت پرداخت بعد از لغو
     */
    private function resolvePaymentStatus(Order $order): string
    {
        $currentStatus = $order->fresh()->{Order::PAYMENT_STATUS};

        if ($currentStatus === Order::PAYMENT_PENDING) {
            return Order::STATUS_CANCELLED;
        }

        return $currentStatus;
    }
}
