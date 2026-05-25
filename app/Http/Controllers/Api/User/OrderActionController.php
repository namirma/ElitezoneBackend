<?php

namespace App\Http\Controllers\Api\User;

use App\Actions\Order\CancelOrderAction;
use App\Actions\Order\FinalizeInvoiceAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Order\FinalizeOrderInvoiceRequest;
use App\Http\Resources\Order\OrderResource;
use App\Models\Order;
use Exception;
use Illuminate\Http\JsonResponse;

class OrderActionController extends Controller
{
    public function __construct(
        private readonly FinalizeInvoiceAction $finalizeAction,
    ) {}

    /**
     * لغو سفارش توسط کاربر
     */
    public function cancel(Order $order, CancelOrderAction $cancelAction): JsonResponse
    {
        $this->authorize('cancel', $order);

        $cancelableStatuses = [
            Order::STATUS_PENDING,
            Order::STATUS_RESERVED_EXTENDED,
            Order::STATUS_PROCESSING,
            Order::STATUS_PREPARING,
            Order::STATUS_WEIGHBRIDGE_DONE,
            Order::STATUS_WAYBILL_ISSUED,
        ];

        if (! in_array($order->status, $cancelableStatuses)) {
            return error(__('orders.cannot_cancel'), [], 400);
        }

        $cancelAction->execute($order, 'لغو توسط کاربر');

        return success(new OrderResource($order->fresh(Order::resourceRelations())), __('orders.cancelled_successfully'));
    }

    /**
     * ثبت فاکتور نهایی و "هولد" کردن سهم مشاور
     */
    public function finalizeInvoice(FinalizeOrderInvoiceRequest $request, Order $order): JsonResponse
    {
        $this->authorize('finalizeInvoice', $order);

        if ($order->status !== Order::STATUS_PROCESSING) {
            return error(__('orders.cannot_finalize_invoice'), [], 400);
        }

        try {
            $this->finalizeAction->execute($order, $request->validated('final_invoice_ref'));
        } catch (Exception $e) {
            return error(__('orders.unknown_processing_error'), [], 500);
        }

        return success([], __('orders.finalize_invoice_success'));
    }
}
