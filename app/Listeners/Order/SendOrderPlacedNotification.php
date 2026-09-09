<?php

namespace App\Listeners\Order;

use App\Events\Order\OrderPlaced;
use App\Models\User;
use App\Services\Notifier;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

class SendOrderPlacedNotification implements ShouldQueue
{
    use InteractsWithQueue;

    public function handle(OrderPlaced $event)
    {
        $order = $event->order;
        $user = $order->placer;

        // `instanceof` و نه فقط `! $user`: رابطهٔ `placer` روی شاخهٔ L13 از
        // دیدِ larastan `Model` است، و این گارد هم null و هم نوعِ اشتباه را
        // با هم رد می‌کند — بدونِ cast و بدونِ حاشیه‌نویسیِ شاخه‌به‌شاخه.
        if (! $user instanceof User) {
            return;
        }

        try {

            $message = __('sms.order_placed', ['order_number' => $order->order_number]);

            if (! $message || $message === 'sms.order_placed') {
                $message = "سفارش شما با شماره {$order->order_number} رزرو شد.\nجهت تکمیل خرید، لطفاً پرداخت را انجام دهید.\nالیت زون";
            }

            Notifier::toUser($user, __('notifications.order_placed_subject'), $message);

            Log::info("Order placed SMS sent for Order #{$order->order_number}");

        } catch (\Exception $e) {
            Log::error('Failed to send order placed SMS: '.$e->getMessage());
        }
    }
}
