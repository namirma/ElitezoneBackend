<?php

namespace App\Http\Controllers\Api\User;

use App\Actions\Financial\RecordSupplierMoadianInvoiceAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Order\AddWaybillRequest;
use App\Http\Requests\Order\SubmitMoadianInvoiceRequest;
use App\Http\Requests\Order\UploadOrderReceiptRequest;
use App\Http\Resources\Order\OrderResource;
use App\Models\Order;
use Exception;
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

            $order->loadMissing(Order::resourceRelations());

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
                Order::WAYBILL_REF => $request->validated('waybill_ref'),
                Order::SHIPPED_AT => now(),
            ]);

            $order->loadMissing(Order::resourceRelations());

            return success(new OrderResource($order), __('orders.waybill_added'));
        }

        return error(__('orders.cannot_add_waybill'), [], 400);
    }

    public function issueEspadanaCommand(Order $order): JsonResponse
    {
        $this->authorize('manageAsSupplier', $order);

        $order->update([Order::ESPADANA_ORDER_REF => 'ESPADANA_'.$order->id.'_'.time()]);

        $order->loadMissing(Order::resourceRelations());

        return success(new OrderResource($order), __('orders.espadana_command_issued'));
    }

    public function attachPaymentReceipt(UploadOrderReceiptRequest $request, Order $order): JsonResponse
    {
        $this->authorize('manageAsSupplier', $order);

        // Private disk — supplier payment receipts must not be reachable via a public URL.
        $path = $request->file('receipt')->store('supplier_receipts', 'financial');
        $order->update([Order::PAYMENT_RECEIPT_REF => $path]);

        $order->loadMissing(Order::resourceRelations());

        return success(new OrderResource($order), __('orders.receipt_attached'));
    }

    /**
     * ثبت فاکتور مودیان سوپلایر — Doc O
     *
     * پس از ثبت، هولد مالیات سوپلایر در WVAT آزاد می‌شود.
     */
    public function submitSupplierMoadianInvoice(
        SubmitMoadianInvoiceRequest $request,
        Order $order,
        RecordSupplierMoadianInvoiceAction $action,
    ): JsonResponse {
        $this->authorize('manageAsSupplier', $order);

        if ($order->{Order::SUPPLIER_MOADIAN_REF} !== null) {
            return error(__('orders.supplier_moadian_already_done'), [], 409);
        }

        try {
            $action->execute($order, $request->validated('moadian_ref'));
        } catch (\RuntimeException $e) {
            return error($e->getMessage(), [], 422);
        } catch (Exception $e) {
            return error(__('orders.unknown_processing_error'), [], 500);
        }

        return success([], __('orders.supplier_moadian_submitted'));
    }
}
