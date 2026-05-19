<?php

namespace App\Http\Controllers\Api\User;

use App\Http\Controllers\Controller;
use App\Http\Requests\AddWaybillRequest;
use App\Http\Requests\UploadOrderReceiptRequest;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use Illuminate\Http\JsonResponse;

class SupplierOrderController extends Controller
{
    public function markAsPrepared(Order $order): JsonResponse
    {
        $this->authorize('manageAsSupplier', $order);

        if ($order->status === Order::STATUS_PROCESSING) {
            $order->update([
                Order::STATUS => Order::STATUS_PREPARING,
                Order::PREPARED_AT => now(),
            ]);

            return success(new OrderResource($order), __('orders.marked_as_prepared'));
        }

        return error(__('orders.cannot_prepare_in_current_state'), [], 400);
    }

    public function addWaybill(AddWaybillRequest $request, Order $order): JsonResponse
    {
        $this->authorize('manageAsSupplier', $order);

        if (in_array($order->status, [Order::STATUS_PREPARING, Order::STATUS_WEIGHBRIDGE_DONE])) {
            $order->update([
                Order::STATUS => Order::STATUS_WAYBILL_ISSUED,
                Order::WAYBILL_REF => $request->validated('waybill_ref'), // اصلاح نام فیلد بر اساس مدل
                Order::SHIPPED_AT => now(),
            ]);

            return success(new OrderResource($order), __('orders.waybill_added'));
        }

        return error(__('orders.cannot_add_waybill'), [], 400);
    }

    public function issueEspadanaCommand(Order $order): JsonResponse
    {
        $this->authorize('manageAsSupplier', $order);

        $order->update([Order::ESPADANA_ORDER_REF => 'ESPADANA_'.$order->id.'_'.time()]);

        return success(new OrderResource($order), __('orders.espadana_command_issued'));
    }

    public function attachPaymentReceipt(UploadOrderReceiptRequest $request, Order $order): JsonResponse
    {
        $this->authorize('manageAsSupplier', $order);

        $path = $request->file('receipt')->store('supplier_receipts', 'public');
        $order->update([Order::PAYMENT_RECEIPT_REF => $path]);

        return success(new OrderResource($order), __('orders.receipt_attached'));
    }
}
