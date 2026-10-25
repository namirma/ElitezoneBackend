<?php

namespace App\Listeners\Order;

use App\Events\Order\OrderPaid;
use App\Models\User;
use App\Services\Notifier;
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

        // `instanceof` و نه فقط `! $user`: رابطهٔ `placer` روی شاخهٔ L13 از
        // دیدِ larastan `Model` است، و این گارد هم null و هم نوعِ اشتباه را
        // با هم رد می‌کند — بدونِ cast و بدونِ حاشیه‌نویسیِ شاخه‌به‌شاخه.
        if (! $user instanceof User) {
            Log::error("OrderPaid Event: Order {$order->id} has no placer user.");

            return;
        }

        Log::info("Processing payment notification for Order #{$order->order_number}");

        try {
            // متن از lang می‌آید، نه هاردکد در PHP — قاعدهٔ صریحِ پروژه.
            Notifier::toUser(
                $user,
                __('notifications.order_paid_subject'),
                __('sms.order_paid_buyer', ['order_number' => $order->order_number])
            );

            //  در آینده ایمیل

        } catch (\Exception $e) {
            Log::error("Failed to send notification for Order {$order->id}: ".$e->getMessage());
        }
    }
}
