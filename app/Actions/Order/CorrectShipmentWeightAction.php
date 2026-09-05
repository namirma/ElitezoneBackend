<?php

declare(strict_types=1);

namespace App\Actions\Order;

use App\Exceptions\BusinessException;
use App\Models\Order;
use App\Models\Shipment;
use App\Services\Weighbridge\WeightSourceResolver;
use Illuminate\Support\Facades\DB;

/**
 * اصلاحِ وزنِ باسکولِ یک محموله — **فقط پیش از اجرای تسویه**.
 *
 * ## چرا لازم بود
 *
 * `RegisterShipmentAction` عمداً idempotent است: ثبتِ دوباره ۴۰۹ می‌گیرد، چون
 * بارنامه یک سندِ رسمی است و شماره‌ی داده‌شده به راننده نباید بی‌صدا باطل شود.
 * ولی همان گارد یعنی **وزنِ اشتباه‌تایپ‌شده هم قفل می‌شد**: تنها راهِ اصلاح یک
 * اندپوینتِ ادمین بود، پس تأمین‌کننده‌ای که یک رقم جا انداخته با تیکت و انتظار
 * روبه‌رو می‌شد — روی عددی که مستقیماً مبلغِ تسویه را تعیین می‌کند.
 *
 * ## مرزِ ایمنی
 *
 * پس از تسویه، پول روی همان عدد جابه‌جا شده: POOM/PTSM محاسبه، کیف‌پول‌ها
 * کسر و واریز، و هولدِ مالیات گذاشته شده. بازنویسیِ وزن در آن حالت یعنی
 * سفارش عددی را نشان بدهد که با دفترِ کیف پول نمی‌خواند — دقیقاً همان
 * واگراییِ بی‌صدایی که `financial:doctor` برای گرفتنش هست.
 *
 * پس مرز صریح است: تا وقتی `weighing_completed_at` خالی است اصلاح یک ویرایشِ
 * داده است؛ بعد از آن، برگرداندنِ پول لازم است و مسیرش تأییدِ اضافه‌بار یا
 * لغوِ اجباریِ ادمین است، نه این اکشن.
 *
 * قفل هم برای همین است: بدونِ `lockForUpdate` روی سفارش، اصلاحِ وزن می‌توانست
 * دقیقاً هم‌زمان با ثبتِ آخرین محموله اجرا شود و تسویه با عددی برود که یک لحظه
 * بعد عوض شده.
 */
class CorrectShipmentWeightAction
{
    public function __construct(
        private readonly WeightSourceResolver $resolver,
    ) {}

    /**
     * @param  array{actual_weight: float|int|string, source?: ?string}  $data
     *
     * @throws BusinessException
     */
    public function execute(Order $order, Shipment $shipment, array $data): Shipment
    {
        if ((int) $shipment->{Shipment::ORDER_ID} !== (int) $order->id) {
            throw new BusinessException(__('shipments.not_part_of_order'), 404);
        }

        return DB::transaction(function () use ($order, $shipment, $data) {
            /** @var Order $lockedOrder */
            $lockedOrder = Order::where(Order::ID, $order->id)->lockForUpdate()->first();

            if ($lockedOrder->{Order::WEIGHING_COMPLETED_AT} !== null) {
                throw new BusinessException(__('shipments.weight_locked_after_settlement'), 409);
            }

            /** @var Shipment $locked */
            $locked = Shipment::where(Shipment::ID, $shipment->id)->lockForUpdate()->first();

            // اصلاح فقط روی محموله‌ای که بارنامه‌اش ثبت شده معنا دارد؛ پیش از
            // آن اصلاً وزنی ثبت نشده که غلط باشد.
            if (! $locked->isRegistered()) {
                throw new BusinessException(__('shipments.not_registered_yet'), 422);
            }

            $source = $this->resolver->for($data['source'] ?? 'manual');

            $locked->forceFill([
                Shipment::ACTUAL_WEIGHT => $source->readWeight($locked, $data),
                Shipment::WEIGHT_SOURCE => $source->code(),
            ])->save();

            return $locked->fresh();
        });
    }
}
