<?php

namespace App\Http\Controllers\Api\User;

use App\Actions\Order\CancelOrderAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Order\FinalizeOrderInvoiceRequest;
use App\Http\Resources\Order\OrderResource;
use App\Models\Order;
use App\Models\User;
use App\Models\WalletTransaction;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class OrderActionController extends Controller
{
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

        $validated = $request->validated();

        if ($order->status !== Order::STATUS_PROCESSING) {
            return error(__('orders.cannot_finalize_invoice'), [], 400);
        }

        $feeAmount = null;
        $consultantUserId = null;

        if ($order->engineer_fee_amount > 0) {
            $feeAmount = $order->engineer_fee_amount;
            $consultantUserId = $order->beneficiary_user_id;
        } elseif ($order->contractor_fee_amount > 0) {
            $feeAmount = $order->contractor_fee_amount;
            $consultantUserId = $order->beneficiary_user_id;
        }

        DB::transaction(function () use ($order, $validated, $feeAmount, $consultantUserId) {
            $order->update([
                Order::FINAL_INVOICE_REF => $validated['final_invoice_ref'],
            ]);

            if ($feeAmount && $consultantUserId) {
                $consultant = User::find($consultantUserId);
                if (! $consultant) {
                    Log::error("User not found for consultant fee hold. Order: {$order->id}");
                    throw new Exception('Consultant user not found.');
                }

                $wallet = $consultant->wallet ?: $consultant->wallet()->create([]);

                $wallet->transactions()->create([
                    'order_id' => $order->id,
                    'amount' => $feeAmount,
                    'type' => WalletTransaction::TYPE_FEE_HOLD,
                    'status' => WalletTransaction::STATUS_PENDING,
                    'description' => 'سهم رزرو شده برای سفارش '.$order->order_number,
                ]);
                $wallet->increment('balance_hold', $feeAmount);
            }
        });

        return success([], __('orders.finalize_invoice_success'));
    }
}
