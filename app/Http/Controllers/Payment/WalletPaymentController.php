<?php

namespace App\Http\Controllers\Payment;

use App\Actions\Financial\ProcessPreScalePaymentAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Payment\PayWithWalletRequest;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use Exception;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class WalletPaymentController extends Controller
{
    public function __construct(
        protected ProcessPreScalePaymentAction $purchaseAction
    ) {}

    public function pay(PayWithWalletRequest $request)
    {
        $user = Auth::user();
        $order = Order::findOrFail($request->order_id);

        if ($order->{Order::PLACER_USER_ID} !== $user->id) {
            return error(__('payment.error.unauthorized'), [], Response::HTTP_FORBIDDEN);
        }

        if ($order->payment_status === Order::PAYMENT_PAID) {
            return error(__('payment.error.already_paid'), [], Response::HTTP_BAD_REQUEST);
        }

        if (! \in_array($order->status, [Order::STATUS_PENDING])) {
            return error(__('orders.cannot_pay_order'), [], Response::HTTP_BAD_REQUEST);
        }

        $wallet = $user->wallet;
        if (! $wallet) {
            return error(__('payment.error.wallet_not_found'), [], Response::HTTP_NOT_FOUND);
        }

        $payableAmount = (float) $order->{Order::FINAL_AMOUNT};
        if ($wallet->balance < $payableAmount) {
            $shortage = $payableAmount - $wallet->balance;

            return response()->json([
                'status' => false,
                'code' => 'INSUFFICIENT_BALANCE',
                'message' => __('payment.error.insufficient_balance'),
                'data' => [
                    'required_amount' => $payableAmount,
                    'current_balance' => (float) $wallet->balance,
                    'shortage_amount' => $shortage,
                    'action' => 'deposit_required',
                ],
            ], Response::HTTP_PAYMENT_REQUIRED);
        }

        try {
            $this->purchaseAction->execute($order);

            return success([
                'order' => new OrderResource($order->fresh()),
                'new_balance' => (float) $user->wallet->fresh()->balance,
                'tracking_code' => $order->id,
            ], __('payment.success.payment_completed'));
        } catch (Exception $e) {
            return error($e->getMessage(), [], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}
