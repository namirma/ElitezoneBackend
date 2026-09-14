<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Order;

use App\Actions\Order\OrderCancelRequestAction;
use App\Exceptions\BusinessException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Order\CancelRequestReasonRequest;
use App\Http\Resources\Order\OrderResource;
use App\Models\Order;
use App\Models\OrderCancelRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

/**
 * لغوِ دوطرفه‌ی سفارش — پرسش ۴ مدیریت. سمتِ خریدار (سایت) و تأمین‌کننده (پنل).
 *
 * یک اندپوینت برای هر دو طرف، و سمتِ درخواست از **هویتِ اکتور** درمی‌آید نه از
 * بدنه: پرداخت‌کننده‌ی سفارش = خریدار، تأمین‌کننده‌ی اقلام = تأمین‌کننده. همان
 * قاعده‌ای که `OverweightApprovalController::reject` دارد.
 *
 * `POST /profile/orders/{order}/cancellation-request` (مسیرِ قدیمیِ پنلِ
 * تأمین‌کننده) هم به همین `store` می‌رسد. آن مسیر پیش از این فقط یک ردیفِ
 * `supplier_requests` می‌ساخت که بک‌آفیس «موافقت» می‌کرد بی‌آنکه چیزی لغو شود
 * (`AdminSupplierRequestController`). تصمیمِ مدیریت جای آن جریان را گرفت؛
 * `SupplierRequestController::cancelOrder` بی‌روت مانده (قانون یک، ردیفِ
 * CLEANUP_CANDIDATES).
 */
class OrderCancelRequestController extends Controller
{
    // POST /api/profile/orders/{order}/cancel-request — خریدار یا تأمین‌کننده.
    public function store(CancelRequestReasonRequest $request, Order $order, OrderCancelRequestAction $action): JsonResponse
    {
        $action->request($order, $this->sideOf($order), (int) Auth::id(), $request->validated('reason'));

        return created(
            new OrderResource($order->fresh(Order::resourceRelations())),
            __('orders.cancel_request.submitted'),
        );
    }

    // POST /api/profile/orders/{order}/cancel-request/accept — طرفِ مقابل → لغو همین حالا.
    public function accept(Order $order, OrderCancelRequestAction $action): JsonResponse
    {
        $action->accept($this->openRequestFor($order));

        return success(
            new OrderResource($order->fresh(Order::resourceRelations())),
            __('orders.cancel_request.accepted'),
        );
    }

    // POST /api/profile/orders/{order}/cancel-request/reject — طرفِ مقابل، با دلیل → ارجاع.
    public function reject(CancelRequestReasonRequest $request, Order $order, OrderCancelRequestAction $action): JsonResponse
    {
        $action->reject($this->openRequestFor($order), $request->validated('reason'));

        return success(
            new OrderResource($order->fresh(Order::resourceRelations())),
            __('orders.cancel_request.rejected'),
        );
    }

    /** خریدار = پرداخت‌کننده (نه ثبت‌کننده — پول به کیف پولِ او برمی‌گردد). */
    private function sideOf(Order $order): string
    {
        $actorId = (int) Auth::id();

        if ($actorId === (int) $order->payerUserId()) {
            return OrderCancelRequest::SIDE_BUYER;
        }

        if ($actorId === $order->supplierUserId()) {
            return OrderCancelRequest::SIDE_SUPPLIER;
        }

        throw new BusinessException(__('global.forbidden'), 403);
    }

    /**
     * درخواستِ در انتظارِ همین سفارش، و فقط اگر اکتور **طرفِ مقابل** باشد:
     * درخواست‌کننده نمی‌تواند به درخواستِ خودش پاسخ دهد.
     */
    private function openRequestFor(Order $order): OrderCancelRequest
    {
        $request = $order->cancelRequests()
            ->where(OrderCancelRequest::STATUS, OrderCancelRequest::STATUS_PENDING)
            ->latest(OrderCancelRequest::ID)
            ->first();

        if (! $request instanceof OrderCancelRequest) {
            throw new BusinessException(__('orders.cancel_request.none_pending'), 404);
        }

        if ($this->sideOf($order) !== $request->counterpartySide()) {
            throw new BusinessException(__('orders.cancel_request.not_your_turn'), 403);
        }

        return $request;
    }
}
