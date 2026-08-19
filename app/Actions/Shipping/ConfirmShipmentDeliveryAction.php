<?php

declare(strict_types=1);

namespace App\Actions\Shipping;

use App\Exceptions\BusinessException;
use App\Jobs\Notification\SendSmsJob;
use App\Models\Order;
use App\Models\Shipment;
use Illuminate\Support\Facades\DB;

/**
 * تأیید تحویل توسط خریدار — بند ۳.۱۰ سند، فاز ۱۰.
 *
 * دو ورودی: پنل خریدار، یا لینک امضاشده‌ی پیامکی. `delivery_confirmed_by`
 * تفکیکشان می‌کند (`null` یعنی از لینک آمده).
 *
 * وضعیتِ خودِ سفارش اینجا **محاسبه** می‌شود، نه دستی نوشته: پاسخ مدیر (دور ۲،
 * پرسش ۷) صریح بود که تحویل جزئی باید از وضعیت محموله‌ها خودکار دربیاید.
 */
class ConfirmShipmentDeliveryAction
{
    public function execute(Shipment $shipment, ?int $confirmedBy = null): Shipment
    {
        return DB::transaction(function () use ($shipment, $confirmedBy) {
            /** @var Shipment $locked */
            $locked = Shipment::where(Shipment::ID, $shipment->id)->lockForUpdate()->first();

            // بند ۹ سند: «تأیید تحویل فقط اگر arrived_at != null». بدون این
            // گارد خریدار می‌توانست باری را که هنوز راه نیفتاده تحویل‌شده
            // اعلام کند و مهلتِ عودت را دور بزند.
            if (! $locked->hasArrived()) {
                throw new BusinessException(__('shipments.not_arrived_yet'), 422);
            }

            if ($locked->isDelivered()) {
                return $locked;
            }

            // محموله‌ای که برگشته دیگر قابل تأیید نیست — چرخه‌اش با ثبت خسارت و
            // حمل مجدد از سر گرفته می‌شود (فاز ۱۱)، نه با یک تأیید دیرهنگام.
            if ($locked->{Shipment::RETURNED_AT} !== null) {
                throw new BusinessException(__('shipments.already_returned'), 422);
            }

            $locked->forceFill([
                Shipment::DELIVERED_AT => now(),
                Shipment::DELIVERY_CONFIRMED_BY => $confirmedBy,
                Shipment::STATUS => Shipment::STATUS_DELIVERED,
            ])->save();

            /** @var Order $order */
            $order = Order::where(Order::ID, $locked->{Shipment::ORDER_ID})->lockForUpdate()->first();

            self::syncOrderStatus($order);
            $this->notifyDriver($locked, $order);

            return $locked;
        });
    }

    /**
     * وضعیت سفارش از روی محموله‌ها — **تنها نویسنده‌ی** `delivered` و
     * `partially_delivered`.
     *
     * قاعده‌ی بند ۳.۱۰:
     *   • همه تحویل شده          ⇒ `delivered`
     *   • بعضی تحویل، بعضی بلاتکلیف ⇒ `partially_delivered`
     *   • هیچ‌کدام تحویل نشده      ⇒ دست نمی‌زنیم (باسکول وضعیت را می‌نویسد)
     *
     * «بلاتکلیف» یعنی نه تحویل و نه عودت. محموله‌ی عودتی تکلیفش روشن است، پس
     * سفارشی که یکی‌اش تحویل و یکی‌اش عودت شده **کامل** است نه جزئی — عودت
     * مسیر مالی خودش را دارد (فاز ۱۱) و نباید سفارش را برای همیشه در
     * `partially_delivered` نگه دارد.
     *
     * static است چون کرانِ انقضای مهلت هم پس از عودتِ یک محموله همین را صدا
     * می‌زند؛ دو کپی از این قاعده یعنی دو تعریف از «تحویل کامل».
     */
    public static function syncOrderStatus(Order $order): void
    {
        $shipments = $order->shipments()->get();

        if ($shipments->isEmpty()) {
            return;
        }

        $delivered = $shipments->filter(fn (Shipment $s) => $s->isDelivered())->count();
        $outstanding = $shipments->filter(fn (Shipment $s) => $s->isOutstanding())->count();

        if ($delivered === 0) {
            return;
        }

        $order->forceFill([
            Order::STATUS => $outstanding === 0
                ? Order::STATUS_DELIVERED
                : Order::STATUS_PARTIALLY_DELIVERED,
        ])->save();
    }

    /** بند ۳.۱۰: «پس از تأیید، پیامک به راننده.» */
    private function notifyDriver(Shipment $shipment, Order $order): void
    {
        $mobile = $shipment->{Shipment::DRIVER_MOBILE};

        if (! $mobile) {
            return;
        }

        SendSmsJob::dispatch(
            $mobile,
            __('sms.delivery_confirmed', ['order_number' => $order->order_number])
        )->afterCommit();
    }
}
