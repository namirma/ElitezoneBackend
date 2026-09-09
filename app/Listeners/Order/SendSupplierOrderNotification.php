<?php

namespace App\Listeners\Order;

use App\Events\Order\OrderPaid;
use App\Services\Notifier;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

/**
 * به تأمین‌کننده خبر می‌دهد که سفارشی پرداخت شد و وارد صفِ کاری‌اش شده.
 *
 * کلِ گردشِ کارِ تأمین‌کننده روی سفارشِ PROCESSING شروع می‌شود، ولی تا پیش از
 * این هیچ چیز به او نمی‌گفت چنین سفارشی وجود دارد — تنها پیامکی که
 * در کلِ سیستم به تأمین‌کننده می‌رفت `supplier_debt_notification` بود، یعنی فقط
 * وقتی بدهکار می‌شد. عملاً باید `/profile/supplier/orders` را دستی poll می‌کرد.
 *
 * یک سفارش = یک تأمین‌کننده (`PlaceOrderAction::assertSingleSupplier`)، ولی چند
 * ردیف می‌تواند داشته باشد؛ پس `unique('id')` لازم است تا دو ردیف از یک کارخانه
 * دو پیامک نفرستد.
 */
class SendSupplierOrderNotification implements ShouldQueue
{
    use InteractsWithQueue;

    public function handle(OrderPaid $event): void
    {
        $order = $event->order;

        $suppliers = $order->items()->with('supplier')->get()
            ->pluck('supplier')
            ->filter()
            ->unique('id');

        if ($suppliers->isEmpty()) {
            Log::channel('orders')->warning('OrderPaid: order has no supplier to notify', [
                'order_id' => $order->id,
            ]);

            return;
        }

        foreach ($suppliers as $supplier) {
            if (empty($supplier->mobile)) {
                Log::channel('orders')->warning('OrderPaid: supplier has no mobile', [
                    'order_id' => $order->id,
                    'supplier_user_id' => $supplier->id,
                ]);

                continue;
            }

            Notifier::toUser(
                $supplier,
                __('notifications.supplier_new_order_subject'),
                __('sms.order_paid_supplier', ['order_number' => $order->order_number])
            );
        }
    }
}
