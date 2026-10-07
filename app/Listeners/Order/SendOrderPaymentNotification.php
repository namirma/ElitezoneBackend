<?php

namespace App\Listeners\Order;

use App\Events\Order\OrderPaid;
use App\Models\User;
use App\Services\Notifier;
use App\Services\Profile\VirtualEmployerAccount;
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

        // `instanceof` و نه فقط `! $user`: رابطه‌ی `placer` روی شاخه‌ی L13 از
        // دیدِ larastan `Model` است، و این گارد هم null و هم نوعِ اشتباه را
        // با هم رد می‌کند — بدونِ cast و بدونِ حاشیه‌نویسیِ شاخه‌به‌شاخه.
        if (! $user instanceof User) {
            Log::error("OrderPaid Event: Order {$order->id} has no placer user.");

            return;
        }

        Log::info("Processing payment notification for Order #{$order->order_number}");

        try {
            // متن از lang می‌آید، نه هاردکد در PHP — قاعده‌ی صریحِ پروژه.
            $subject = __('notifications.order_paid_subject');
            $params = ['order_number' => $order->order_number];

            Notifier::template($user, $subject, 'order_paid_buyer', $params);

            // در «خرید برای دیگری» پول از کیف پولِ **پرداخت‌کننده** رفته، ولی فقط
            // ثبت‌کننده (الیت‌یار) خبر می‌گرفت — کارفرمایی که پرداخته بود هیچ.
            $payer = User::find($order->payerUserId());

            if ($payer instanceof User && $payer->id !== $user->id) {
                Notifier::template($payer, $subject, 'order_paid_buyer', $params);
            }

            // حسابِ مجازیِ کارفرما (مدیریت، دورِ هفتم، ۷-۵): «پس از پرداخت کارفرما یک پیامک برای او می رود … در این پیامک
            // یک لینک تایید حساب و لاگین وجود دارد».
            if ($payer instanceof User && $payer->isVirtualAccount()) {
                app(VirtualEmployerAccount::class)->sendClaimLink($payer, $order);
            }

            //  در آینده ایمیل

        } catch (\Exception $e) {
            Log::error("Failed to send notification for Order {$order->id}: ".$e->getMessage());
        }
    }
}
