<?php

declare(strict_types=1);

namespace App\Actions\Order;

use App\Actions\Financial\ProcessWeighbridgeResultAction;
use App\Exceptions\BusinessException;
use App\Models\Order;
use App\Models\Shipment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * ثبت بارنامه توسط تأمین‌کننده — بارنامه و باسکول در **یک** اقدام (بند ۳.۷).
 *
 * پیش از این، وزنِ باسکول از یک اندپوینتِ ادمین
 * (`OrderWeighbridgeController`) ثبت می‌شد و بارنامه از یک اندپوینتِ جداگانه‌ی
 * تأمین‌کننده که در فاز ۰ حذف شد. نتیجه این بود که تنها نویسنده‌ی وضعیت
 * `shipped` دستِ ادمین بود، در حالی که کسی که واقعاً بار را می‌فرستد
 * تأمین‌کننده است. حالا همان کسی که بار را تحویل راننده می‌دهد، وزن و مشخصات
 * راننده را با هم ثبت می‌کند.
 *
 * **چرا per-shipment و نه per-order:** یک سفارش می‌تواند چند مبدأ→مقصد داشته
 * باشد و هر لِگ بارنامه و راننده‌ی خودش را دارد. ولی تسویه‌ی مالی در این فاز
 * هنوز سطحِ سفارش است، پس محاسبه تنها وقتی اجرا می‌شود که **همه‌ی** محموله‌ها
 * ثبت شده باشند و ورودی‌اش مجموعِ وزن‌هاست. شکستنِ خودِ تسویه به ازای هر
 * محموله (و شاخه‌ی اضافه‌بار) کارِ فاز ۸ است.
 */
class RegisterShipmentAction
{
    public function __construct(
        private readonly ProcessWeighbridgeResultAction $weighbridge,
    ) {}

    /**
     * @param  array{waybill_ref: string, driver_name: string, driver_national_code?: ?string,
     *               driver_mobile?: ?string, vehicle_plate: string, vehicle_type?: ?string,
     *               actual_weight: float}  $data
     * @return array{shipment: Shipment, order_completed: bool, settlement: ?array}
     *
     * @throws BusinessException
     */
    public function execute(Order $order, Shipment $shipment, array $data): array
    {
        if ((int) $shipment->{Shipment::ORDER_ID} !== (int) $order->id) {
            throw new BusinessException(__('shipments.not_part_of_order'), 404);
        }

        [$locked, $allRegistered, $totalWeight] = DB::transaction(function () use ($order, $shipment, $data) {
            /** @var Shipment $lockedShipment */
            $lockedShipment = Shipment::where(Shipment::ID, $shipment->id)->lockForUpdate()->first();

            // idempotency: بارنامه یک سندِ رسمی است. ثبتِ دوباره یعنی شماره‌ی
            // قبلی — که به راننده و به سیستمِ بیرونی داده شده — بی‌صدا باطل شود.
            if ($lockedShipment->isRegistered()) {
                throw new BusinessException(__('shipments.already_registered'), 409);
            }

            /** @var Order $lockedOrder */
            $lockedOrder = Order::where(Order::ID, $order->id)->lockForUpdate()->first();

            // فقط سفارشِ پرداخت‌شده‌ای که در آماده‌سازی است بار می‌زند. بدون این
            // گارد، بارنامه روی سفارشِ لغوشده یا رزروِ پرداخت‌نشده هم ثبت می‌شد.
            if ($lockedOrder->{Order::PAYMENT_STATUS} !== Order::PAYMENT_PAID
                || $lockedOrder->{Order::STATUS} !== Order::STATUS_PROCESSING) {
                throw new BusinessException(__('shipments.order_not_shippable'), 422);
            }

            $lockedShipment->forceFill([
                Shipment::WAYBILL_REF => $data['waybill_ref'],
                Shipment::DRIVER_NAME => $data['driver_name'],
                Shipment::DRIVER_NATIONAL_CODE => $data['driver_national_code'] ?? null,
                Shipment::DRIVER_MOBILE => $data['driver_mobile'] ?? null,
                Shipment::VEHICLE_PLATE => $data['vehicle_plate'],
                Shipment::VEHICLE_TYPE => $data['vehicle_type'] ?? null,
                Shipment::ACTUAL_WEIGHT => $data['actual_weight'],
                Shipment::REGISTERED_AT => now(),
                Shipment::STATUS => Shipment::STATUS_SHIPPED,
            ])->save();

            // شمارش زیرِ همان تراکنش: دو تأمین‌کننده‌ای که هم‌زمان آخرین دو
            // محموله را ثبت کنند، وگرنه هر دو «آخرین» به نظر می‌رسیدند و
            // تسویه دو بار اجرا می‌شد.
            $remaining = Shipment::where(Shipment::ORDER_ID, $lockedOrder->id)
                ->whereNull(Shipment::REGISTERED_AT)
                ->lockForUpdate()
                ->count();

            $registered = Shipment::where(Shipment::ORDER_ID, $lockedOrder->id)
                ->whereNotNull(Shipment::REGISTERED_AT)
                ->get();

            $total = (float) $registered->sum(fn (Shipment $s) => (float) $s->{Shipment::ACTUAL_WEIGHT});

            if ($remaining === 0) {
                // فهرستِ شماره‌ها روی سفارش می‌ماند تا تایم‌لاین و فیلترِ اسنادِ
                // ادمین که این ستون را می‌خوانند بدون تغییر کار کنند.
                $lockedOrder->forceFill([
                    Order::WAYBILL_REF => $registered
                        ->pluck(Shipment::WAYBILL_REF)
                        ->filter()
                        ->implode('، '),
                ])->save();
            }

            return [$lockedOrder, $remaining === 0, $total];
        });

        if (! $allRegistered) {
            Log::channel('financial')->info(
                "Shipment {$shipment->id} registered for order {$order->id}; awaiting the remaining legs."
            );

            return [
                'shipment' => $shipment->fresh(),
                'order_completed' => false,
                'settlement' => null,
            ];
        }

        // تسویه **بیرون** از تراکنشِ بالا اجرا می‌شود چون خودش تراکنش و قفلِ
        // خودش را می‌گیرد (و سند PDF می‌سازد). تو در تو کردنشان یعنی قفل‌ها را
        // روی I/O دیسک نگه داریم.
        $settlement = $this->weighbridge->execute($locked, $totalWeight);

        return [
            'shipment' => $shipment->fresh(),
            'order_completed' => true,
            'settlement' => $settlement,
        ];
    }
}
