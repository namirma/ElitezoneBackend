<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Shipping;

use App\Actions\Shipping\ConfirmShipmentDeliveryAction;
use App\Actions\Shipping\RecordShipmentArrivalAction;
use App\Exceptions\BusinessException;
use App\Http\Controllers\Controller;
use App\Http\Resources\Order\ShipmentResource;
use App\Models\Order;
use App\Models\Shipment;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

/**
 * چرخه‌ی تحویل — فاز ۱۰، بند ۷.۴ و ۷.۵ سند.
 *
 * سه در ورودی دارد و عمداً در یک کنترلر جمع‌اند، چون هر سه یک اتفاق را ثبت
 * می‌کنند و فقط در «چه کسی گفته» فرق دارند:
 *   • راننده، با لینک امضاشده و **بدون احراز هویت** (بند ۳.۹: راننده حساب
 *     کاربری ندارد)؛
 *   • خریدار، از پنل یا از لینک امضاشده‌ی پیامکی؛
 *   • بک‌آفیس، با تماس تلفنی (اندپوینتش در `AdminOrderController` است).
 */
class ShipmentDeliveryController extends Controller
{
    /**
     * GET /api/shipments/{shipment}/arrived — middleware `signed`، بدون auth.
     *
     * راننده روی لینک پیامکی می‌زند. امضا تنها گاردِ این مسیر است، پس هیچ
     * پارامتری از بدنه خوانده نمی‌شود.
     */
    public function arrived(Shipment $shipment, RecordShipmentArrivalAction $action): JsonResponse
    {
        $action->execute($shipment, Shipment::ARRIVAL_DRIVER_LINK);

        return success(
            new ShipmentResource($shipment->fresh()),
            __('shipments.arrival_recorded')
        );
    }

    /**
     * POST /api/profile/shipments/{shipment}/confirm-delivery — خریدار، از پنل.
     *
     * فقط `payerUserId` مجاز است (بند ۹ سند). ثبت‌کننده‌ای که پول نداده — مثلاً
     * الیت‌یار در خرید برای دیگری — حق تأیید تحویل ندارد، چون این تأیید همان
     * چیزی است که جلوی عودت و بدهیِ بعدی را می‌گیرد.
     */
    public function confirm(Shipment $shipment, ConfirmShipmentDeliveryAction $action): JsonResponse
    {
        // ‏`Order::find` و نه `$shipment->order`: دومی روی شاخه‌ی L13
        // non-nullable تایپ می‌شود، پس گاردِ null «همیشه false» گزارش می‌شد و
        // `payerUserId()` روی `Model` پیدا نمی‌شد.
        /** @var Order|null $order */
        $order = Order::whereKey($shipment->{Shipment::ORDER_ID})->first();

        if (! $order || (int) $order->payerUserId() !== (int) Auth::id()) {
            throw new BusinessException(__('global.forbidden'), 403);
        }

        $action->execute($shipment, (int) Auth::id());

        return success(
            new ShipmentResource($shipment->fresh()),
            __('shipments.delivery_confirmed')
        );
    }

    /**
     * GET /api/delivery/{shipment}/confirm — middleware `signed`، بدون auth.
     *
     * همان تأیید، از لینک پیامکی. `confirmedBy` عمداً `null` می‌ماند تا در
     * تاریخچه معلوم باشد تأیید از پنل نیامده است.
     */
    public function confirmSigned(Shipment $shipment, ConfirmShipmentDeliveryAction $action): JsonResponse
    {
        $action->execute($shipment, null);

        return success(
            new ShipmentResource($shipment->fresh()),
            __('shipments.delivery_confirmed')
        );
    }
}
