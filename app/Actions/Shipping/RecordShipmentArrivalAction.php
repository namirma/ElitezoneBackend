<?php

declare(strict_types=1);

namespace App\Actions\Shipping;

use App\Exceptions\BusinessException;
use App\Jobs\Notification\SendSmsJob;
use App\Models\Order;
use App\Models\Shipment;
use App\Models\User;
use App\Services\Sales\SalesSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;

/**
 * رسیدن راننده — بند ۳.۱۰ سند، فاز ۱۰.
 *
 * دو ورودی دارد و هر دو به اینجا می‌رسند:
 *   • راننده، با لینک یکبارمصرفِ امضاشده (حساب کاربری ندارد — بند ۳.۹)؛
 *   • بک‌آفیس، با تماس تلفنی. `arrival_source` تفکیکشان می‌کند تا بعداً
 *     معلوم باشد چه کسی گفته بار رسیده است.
 *
 * ماشه‌ی اصلی این است: از همین لحظه `delivery_deadline` شروع می‌شود و اگر
 * خریدار تا پایانش تأیید نکند، محموله به مبدأ برمی‌گردد (فاز ۱۱).
 */
class RecordShipmentArrivalAction
{
    public function __construct(
        private readonly SalesSettings $settings,
    ) {}

    public function execute(Shipment $shipment, string $source, ?int $actorId = null): Shipment
    {
        return DB::transaction(function () use ($shipment, $source, $actorId) {
            /** @var Shipment $locked */
            $locked = Shipment::where(Shipment::ID, $shipment->id)->lockForUpdate()->first();

            // بارنامه نخورده یعنی این محموله اصلاً حرکت نکرده. بدون این گارد،
            // یک لینکِ لو رفته می‌توانست مهلتِ تحویلِ باری را شروع کند که هنوز
            // در کارخانه است — و پنج ساعت بعد همان بار «عودت‌شده» می‌شد.
            if (! $locked->isRegistered()) {
                throw new BusinessException(__('shipments.not_shipped_yet'), 422);
            }

            // idempotent: راننده ممکن است لینک را دوباره باز کند. ثبتِ دوباره
            // یعنی مهلت از نو شروع شود، که به ضرر خریدار تمام نمی‌شود ولی
            // تاریخچه را دروغ می‌کند.
            if ($locked->hasArrived()) {
                return $locked;
            }

            $hours = (int) ($locked->{Shipment::DELIVERY_DEADLINE_HOURS} ?: $this->settings->deliveryDeadlineHours());
            $now = now();

            $locked->forceFill([
                Shipment::ARRIVED_AT => $now,
                Shipment::ARRIVAL_SOURCE => $source,
                Shipment::DELIVERY_DEADLINE_HOURS => $hours,
                // اسنپ‌شات، نه بازخوانی: تغییرِ بعدیِ تنظیم نباید مهلتِ باری که
                // همین حالا سرِ در انبار مشتری است را جابه‌جا کند.
                Shipment::DELIVERY_DEADLINE => $now->copy()->addHours($hours),
                Shipment::STATUS => Shipment::STATUS_ARRIVED,
            ])->save();

            $this->notifyBuyer($locked, $actorId);

            return $locked;
        });
    }

    /**
     * پیامکِ «راننده رسید» همراه لینک تأیید تحویل.
     *
     * لینک امضاشده است چون خریدار ممکن است اپ را باز نکند و بند ۳.۱۰ صریحاً
     * «پنل خود یا لینک پیامکی» می‌گوید. انقضای لینک همان مهلت تحویل است — نه
     * بیشتر، وگرنه پس از عودت هم قابل استفاده می‌ماند.
     */
    private function notifyBuyer(Shipment $shipment, ?int $actorId): void
    {
        /** @var Order|null $order */
        $order = Order::whereKey($shipment->{Shipment::ORDER_ID})->first();

        if (! $order) {
            return;
        }

        // ‏`User::find` و نه `$order->payer ?? $order->placer`: دومی روی شاخه‌ی
        // L13 به `Model` تایپ می‌شود و `->mobile` خطای خاصیتِ تعریف‌نشده می‌دهد.
        // `payerUserId()` خودش فالبکِ ثبت‌کننده را دارد (فاز ۶).
        /** @var User|null $buyer */
        $buyer = User::whereKey($order->payerUserId())->first();

        if (! $buyer?->mobile) {
            return;
        }

        $link = URL::temporarySignedRoute(
            'delivery.confirm',
            $shipment->{Shipment::DELIVERY_DEADLINE},
            ['shipment' => $shipment->id],
        );

        SendSmsJob::template($buyer->mobile, 'driver_arrived', [
            'order_number' => $order->order_number,
            'link' => $link,
        ])->afterCommit();
    }
}
