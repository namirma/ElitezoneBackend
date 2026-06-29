<?php

namespace App\Listeners\Order;

use App\Events\Order\OrderCancelled;
use App\Jobs\Notification\SendSmsJob;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

class SendOrderCancellationNotification implements ShouldQueue
{
    use InteractsWithQueue;

    public function handle(OrderCancelled $event)
    {
        $order = $event->order;
        $user = $order->placer;

        if (! $user) {
            return;
        }

        try {
            $message = "سفارش {$order->order_number} لغو شد.\n";
            if ($order->payment_status === 'refunded') {
                $message .= '.مبلغ سفارش به کیف پول شما بازگشت داده شد';
            }
            $message .= "\nالیت زون";

            SendSmsJob::dispatch($user->mobile, $message);

            Log::info("Cancellation SMS sent for Order {$order->id}");
        } catch (\Exception $e) {
            Log::error('Failed to send cancellation SMS: '.$e->getMessage());
        }
    }
}
