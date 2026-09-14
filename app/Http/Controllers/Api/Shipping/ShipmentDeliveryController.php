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
use Illuminate\Http\Request;
use Illuminate\Http\Response;
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
     * فقط صفحه‌ی تأیید. ⚠️ هیچ‌چیز ثبت نمی‌شود: پیش‌نمایشِ لینک در پیام‌رسان همین
     * URL را GET/HEAD می‌کند (توضیح در `routes/api/public.php`).
     */
    public function arrivedPage(Request $request, Shipment $shipment): Response
    {
        return $this->linkPage($request, $shipment, 'arrival');
    }

    /**
     * POST /api/shipments/{shipment}/arrived — دکمه‌ی همان صفحه.
     *
     * راننده روی لینک پیامکی می‌زند. امضا تنها گاردِ این مسیر است، پس هیچ
     * پارامتری از بدنه خوانده نمی‌شود.
     */
    public function arrived(Request $request, Shipment $shipment, RecordShipmentArrivalAction $action): JsonResponse|Response
    {
        return $this->respondToLink(
            $request,
            $shipment,
            fn () => $action->execute($shipment, Shipment::ARRIVAL_DRIVER_LINK),
            __('shipments.arrival_recorded'),
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
     * فقط صفحه‌ی تأیید؛ ثبتِ تحویل با POST. تحویلِ ثبت‌شده پنجره‌ی اصلاحِ وزنِ
     * باسکول را می‌بندد، پس یک پیش‌واکشی نباید بتواند انجامش دهد.
     */
    public function confirmPage(Request $request, Shipment $shipment): Response
    {
        return $this->linkPage($request, $shipment, 'confirm');
    }

    /**
     * POST /api/delivery/{shipment}/confirm — همان تأیید، از لینک پیامکی.
     *
     * `confirmedBy` عمداً `null` می‌ماند تا در تاریخچه معلوم باشد تأیید از پنل
     * نیامده است.
     */
    public function confirmSigned(Request $request, Shipment $shipment, ConfirmShipmentDeliveryAction $action): JsonResponse|Response
    {
        return $this->respondToLink(
            $request,
            $shipment,
            fn () => $action->execute($shipment, null),
            __('shipments.delivery_confirmed'),
        );
    }

    private function linkPage(Request $request, Shipment $shipment, string $kind): Response
    {
        $orderNumber = (string) Order::whereKey($shipment->{Shipment::ORDER_ID})->value(Order::ORDER_NUMBER);

        return response()->view('shipments.signed-link', [
            'title' => __("shipments.link_{$kind}_title"),
            'body' => __("shipments.link_{$kind}_body", ['order_number' => $orderNumber]),
            'button' => __("shipments.link_{$kind}_button"),
            // POST به همان URL: امضا روی مسیر + query است و `signed` دوباره می‌سنجدش.
            'action' => $request->fullUrl(),
        ]);
    }

    /**
     * راننده/خریدار در مرورگرِ گوشی است، پس پاسخِ دکمه صفحه است نه JSON. کلاینتی
     * که JSON می‌خواهد همان پاسخ و خطای قبلی را می‌گیرد.
     */
    private function respondToLink(Request $request, Shipment $shipment, callable $run, string $done): JsonResponse|Response
    {
        try {
            $run();
        } catch (BusinessException $e) {
            if ($request->expectsJson()) {
                throw $e;
            }

            return response()->view('shipments.signed-link', [
                'title' => __('shipments.link_failed_title'),
                'body' => $e->getMessage(),
                'button' => null,
                'action' => null,
            ], 422);
        }

        if ($request->expectsJson()) {
            return success(new ShipmentResource($shipment->fresh()), $done);
        }

        return response()->view('shipments.signed-link', [
            'title' => $done,
            'body' => null,
            'button' => null,
            'action' => null,
        ]);
    }
}
