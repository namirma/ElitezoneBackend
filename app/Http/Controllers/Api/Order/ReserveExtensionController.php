<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Order;

use App\Actions\Order\ReserveExtensionAction;
use App\Exceptions\BusinessException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Order\ProposeReserveExtensionRequest;
use App\Http\Resources\Order\OrderResource;
use App\Models\Order;
use App\Models\OrderExtensionOffer;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

/**
 * تمدیدِ رزرو با پیشنهادِ تأمین‌کننده و پاسخِ خریدار — پرسش ۳ مدیریت.
 *
 * دو اکتور، دو گروهِ اندپوینت — همان دلیلِ `OverweightApprovalController`: اگر
 * یک اندپوینتِ مشترک بود، نقشِ پاسخ‌دهنده از بدنه خوانده می‌شد و قابلِ جعل بود.
 */
class ReserveExtensionController extends Controller
{
    // POST /api/profile/orders/{order}/extension — تأمین‌کننده.
    public function propose(ProposeReserveExtensionRequest $request, Order $order, ReserveExtensionAction $action): JsonResponse
    {
        $this->authorize('manageAsSupplier', $order);

        $action->propose($order, (int) Auth::id(), $request->integer('hours'));

        return created(
            new OrderResource($order->fresh(Order::resourceRelations())),
            __('orders.extension.proposed'),
        );
    }

    // POST /api/profile/orders/{order}/extension/{offer}/accept — خریدار (پرداخت‌کننده).
    public function accept(Order $order, OrderExtensionOffer $offer, ReserveExtensionAction $action): JsonResponse
    {
        $this->assertBuyerOf($order, $offer);

        $action->accept($offer);

        return success(
            new OrderResource($order->fresh(Order::resourceRelations())),
            __('orders.extension.accepted'),
        );
    }

    // POST /api/profile/orders/{order}/extension/{offer}/reject — خریدار؛ = لغوِ رزرو.
    public function reject(Order $order, OrderExtensionOffer $offer, ReserveExtensionAction $action): JsonResponse
    {
        $this->assertBuyerOf($order, $offer);

        $action->reject($offer);

        return success(
            new OrderResource($order->fresh(Order::resourceRelations())),
            __('orders.extension.rejected'),
        );
    }

    /**
     * فقط پرداخت‌کننده — نه ثبت‌کننده — و فقط پیشنهادی که مالِ همین سفارش است.
     *
     * پذیرش یعنی بیعانه‌ی او همچنان در هولد می‌ماند و رد یعنی برمی‌گردد؛ هر دو
     * تصمیمی روی پولِ اوست. ذی‌نفعی که پول نداده حق تصمیم ندارد (قاعده‌ی
     * «پرداخت‌کننده» تیم بک‌اند).
     */
    private function assertBuyerOf(Order $order, OrderExtensionOffer $offer): void
    {
        if ((int) $offer->{OrderExtensionOffer::ORDER_ID} !== (int) $order->getKey()) {
            throw new BusinessException(__('global.model_not_found'), 404);
        }

        if ((int) $order->payerUserId() !== (int) Auth::id()) {
            throw new BusinessException(__('global.forbidden'), 403);
        }
    }
}
