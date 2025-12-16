<?php

namespace App\Http\Controllers\Api\User;

use App\Actions\Order\PayOrderAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\PayFullOrderRequest;
use App\Http\Requests\PayHoldFeeRequest;
use App\Http\Requests\UploadOrderReceiptRequest;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Exception;

class OrderPaymentController extends Controller
{
    public function payHoldFee(PayHoldFeeRequest $request, Order $order): JsonResponse
    {
        if ($order->status !== Order::STATUS_PENDING) {
            return error(__('orders.cannot_extend_reservation_status'), [], 400);
        }
        if ($order->reservation_expires_at < now()) {
            return error(__('orders.cannot_extend_reservation_expired'), [], 400);
        }

        $order->update([
            Order::STATUS => Order::STATUS_RESERVED_EXTENDED,
            Order::RESERVATION_EXPIRES_AT => now()->addDay(),
            Order::PARTIAL_PAYMENT_AMOUNT => $request->validated('amount'),
        ]);

        return success(new OrderResource($order->fresh()), __('orders.reservation_extended'));
    }

    public function payFullOrder(PayFullOrderRequest $request, Order $order, PayOrderAction $payOrderAction): JsonResponse
    {
        $this->authorize('pay', $order);

        $payableStatuses = [Order::STATUS_PENDING, Order::STATUS_RESERVED_EXTENDED];
        if (!in_array($order->status, $payableStatuses)) {
            return error(__('orders.cannot_pay_order'), [], 400);
        }

        $gateway = $request->validated('gateway');

        if ($gateway === 'wallet') {
            try {
                $payOrderAction->execute(Auth::user(), $order, 'wallet');
                return success(new OrderResource($order->fresh()), __('orders.payment_success_wallet'));
            } catch (Exception $e) {
                return error($e->getMessage(), [], 400);
            }
        }

        // منطق فرضی درگاه بانکی
        $paymentUrl = "https://shaparak-mock.com/pay?order={$order->id}&provider={$gateway}";
        return success(['redirect_url' => $paymentUrl], __('orders.payment_redirect'));
    }

    public function uploadReceipt(UploadOrderReceiptRequest $request, Order $order): JsonResponse
    {
        $this->authorize('pay', $order);

        if ($order->payment_status === Order::PAYMENT_PAID) {
            return error(__('orders.already_paid'), [], 400);
        }

        $path = $request->file('receipt')->store('order_receipts', 'public');
        $order->update([Order::PAYMENT_RECEIPT_REF => $path]);

        return success(new OrderResource($order), __('orders.receipt_uploaded_success'));
    }
}
