<?php

namespace App\Listeners\Order;

use App\Events\Order\OrderPaid;
use App\Jobs\SendSmsJob;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

class SendOrderPaymentNotification implements ShouldQueue
{
    use InteractsWithQueue;

    /**
     * Handle the event.
     *
     * @return void
     */
    public function handle(OrderPaid $event)
    {
        $order = $event->order;
        $user = $order->placer;

        if (! $user) {
            Log::error("OrderPaid Event: Order {$order->id} has no placer user.");

            return;
        }

        Log::info("Processing payment notification for Order #{$order->order_number}");

        try {
            // متن پیامک
            $message = "کاربر گرامی، سفارش شما با شماره {$order->order_number} با موفقیت ثبت شد.\nالیت زون";

            SendSmsJob::dispatch($user->mobile, $message);

            //  در آینده ایمیل

        } catch (\Exception $e) {
            Log::error("Failed to send notification for Order {$order->id}: ".$e->getMessage());
        }
    }
}
