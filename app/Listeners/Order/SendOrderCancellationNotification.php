<?php

namespace App\Listeners\Order;

use App\Events\Order\OrderCancelled;
use App\Models\Order;
use App\Models\User;
use App\Services\Notifier;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

class SendOrderCancellationNotification implements ShouldQueue
{
    use InteractsWithQueue;

    /**
     * ⚠️ استرداد به کیف پولِ **پرداخت‌کننده** می‌رود (`creditBuyer` → `payerUserId()`)،
     * ولی این پیام تا امروز فقط به **ثبت‌کننده** می‌رفت. در «خرید برای دیگری» یعنی
     * الیت‌یار می‌خواند «مبلغ به کیف پول شما بازگشت داده شد» در حالی که کیف پولش
     * تکان نخورده بود، و کارفرمایی که پول داده بود هیچ خبری نمی‌گرفت.
     *
     * حالا پرداخت‌کننده پیامِ استرداد را می‌گیرد؛ ثبت‌کننده اگر کسِ دیگری است، فقط
     * خبرِ لغو را.
     */
    public function handle(OrderCancelled $event)
    {
        $order = $event->order;
        $placer = $order->placer;
        $payer = User::find($order->payerUserId());

        try {
            $refunded = $order->{Order::PAYMENT_STATUS} === Order::PAYMENT_REFUNDED;

            if ($payer instanceof User) {
                Notifier::toUser($payer, __('notifications.order_cancelled_subject'), $this->message($order, $refunded));
            }

            if ($placer instanceof User && (! $payer instanceof User || $placer->id !== $payer->id)) {
                Notifier::toUser($placer, __('notifications.order_cancelled_subject'), $this->message($order, false));
            }

            Log::info("Cancellation SMS sent for Order {$order->id}");
        } catch (\Exception $e) {
            Log::error('Failed to send cancellation SMS: '.$e->getMessage());
        }
    }

    private function message(Order $order, bool $withRefund): string
    {
        $message = "سفارش {$order->order_number} لغو شد.\n";

        if ($withRefund) {
            $message .= '.مبلغ سفارش به کیف پول شما بازگشت داده شد';
        }

        return $message."\nالیت زون";
    }
}
