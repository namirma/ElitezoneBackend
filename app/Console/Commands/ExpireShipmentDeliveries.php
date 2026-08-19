<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Shipping\ConfirmShipmentDeliveryAction;
use App\Jobs\Notification\SendSmsJob;
use App\Models\Order;
use App\Models\Shipment;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * محموله‌ای که تا پایان مهلت تأیید نشده ⇒ عودت به مبدأ.
 *
 * بند ۳.۱۱ سند و پاسخ مدیر (دور ۲، پرسش ۸): «عدم تأیید تا پایان
 * `delivery_deadline` ⇒ محموله `returned`، سفارش `returned_to_origin`».
 *
 * ⚠️ **هیچ لِگ مالی اینجا اجرا نمی‌شود** (بند ۸.۳). اصل مالکیت این است: کالا
 * از آنِ خریدار و پول از آنِ تأمین‌کننده. با عودت، تأمین‌کننده بدهکار نمی‌شود —
 * کالا را نگه می‌دارد و هزینه‌هایش را جداگانه به‌عنوان خسارت ثبت می‌کند
 * (فاز ۱۱). پس این کامند فقط وضعیت می‌نویسد و خبر می‌دهد.
 *
 * کران و نه job زمان‌بندی‌شده — بند ۱۰.۱: مهلت قابل‌تمدید است و یک job
 * delay-دار را نمی‌شود لغو کرد.
 */
class ExpireShipmentDeliveries extends Command
{
    protected $signature = 'shipments:expire-delivery';

    protected $description = 'محموله‌های تأییدنشده پس از مهلت تحویل را به مبدأ عودت می‌دهد';

    public function handle(): int
    {
        $returned = 0;

        Shipment::query()
            ->whereNotNull(Shipment::ARRIVED_AT)
            ->whereNull(Shipment::DELIVERED_AT)
            ->whereNull(Shipment::RETURNED_AT)
            ->whereNotNull(Shipment::DELIVERY_DEADLINE)
            ->where(Shipment::DELIVERY_DEADLINE, '<=', now())
            ->with(['order.items.supplier', 'order.payer', 'order.placer'])
            ->chunkById(100, function ($shipments) use (&$returned) {
                foreach ($shipments as $shipment) {
                    try {
                        $this->returnToOrigin($shipment);
                        $returned++;
                    } catch (\Throwable $e) {
                        Log::channel('financial')->error(
                            "shipments:expire-delivery failed for shipment {$shipment->id}: ".$e->getMessage()
                        );
                    }
                }
            });

        $this->info("Returned {$returned} shipment(s) to origin.");

        return self::SUCCESS;
    }

    private function returnToOrigin(Shipment $shipment): void
    {
        DB::transaction(function () use ($shipment) {
            /** @var Shipment $locked */
            $locked = Shipment::where(Shipment::ID, $shipment->id)->lockForUpdate()->first();

            // دوباره چک می‌شود چون بین کوئری و قفل، خریدار ممکن است تأیید کرده
            // باشد — و یک تأییدِ به‌موقع نباید با عودت پاک شود.
            if ($locked->isDelivered() || $locked->{Shipment::RETURNED_AT} !== null) {
                return;
            }

            $locked->forceFill([
                Shipment::RETURNED_AT => now(),
                Shipment::STATUS => Shipment::STATUS_RETURNED,
            ])->save();

            /** @var Order $order */
            $order = Order::where(Order::ID, $locked->{Shipment::ORDER_ID})->lockForUpdate()->first();

            // اگر محموله‌ی دیگری تحویل شده باشد، سفارش «تحویل جزئی/کامل» است و
            // نباید یکجا `returned_to_origin` شود؛ همان قاعده‌ی محاسبه‌شده‌ی
            // تأیید تحویل اینجا هم اعمال می‌گردد.
            $anyDelivered = $order->shipments()->whereNotNull(Shipment::DELIVERED_AT)->exists();

            if ($anyDelivered) {
                ConfirmShipmentDeliveryAction::syncOrderStatus($order);
            } else {
                $order->forceFill([Order::STATUS => Order::STATUS_RETURNED_TO_ORIGIN])->save();
            }

            $this->notify($locked, $order);
        });
    }

    /** بند ۱۰.۲: `shipment_returned` به **هر دو** طرف می‌رود. */
    private function notify(Shipment $shipment, Order $order): void
    {
        $message = __('sms.shipment_returned', ['order_number' => $order->order_number]);

        $buyer = $order->payer ?? $order->placer;
        if ($buyer?->mobile) {
            SendSmsJob::dispatch($buyer->mobile, $message)->afterCommit();
        }

        $supplier = $order->items->first()?->supplier;
        if ($supplier?->mobile) {
            SendSmsJob::dispatch($supplier->mobile, $message)->afterCommit();
        }
    }
}
