<?php

namespace App\Http\Controllers;

use App\Actions\Order\PlaceOrderAction;
use App\DataTransferObjects\PlaceOrderData;
use App\Http\Requests\StoreOrderRequest;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

class CheckoutController extends Controller
{
    /**
     * دریافت اطلاعات پرداخت سفارش
     */
    public function paymentInfo(Order $order): JsonResponse
    {
        $user = Auth::user();

        // بررسی دسترسی
        if ($order->placer_user_id !== $user->id && $order->beneficiary_user_id !== $user->id) {
            return error(__('auth.forbidden'), [], 403);
        }

        if ($order->payment_status === Order::PAYMENT_PAID) {
            return error(__('checkout.order_already_paid'), [], 400);
        }

        $walletBalance = $user->wallet->balance ?? 0;

        $data = [
            'order_id'       => $order->id,
            'order_number'   => $order->order_number,
            'payable_amount' => (string) $order->final_amount,
            'items_count'    => $order->items()->count(),
            'created_at_p'   => $order->created_at_p,
            'gateways'       => [
                [
                    'key'   => 'shaparak',
                    'title' => __('checkout.gateways.internet'),
                    'icon'  => 'card',
                ],
                [
                    'key'        => 'wallet',
                    'title'      => __('checkout.gateways.wallet'),
                    'balance'    => (string) $walletBalance,
                    'has_credit' => $walletBalance >= $order->final_amount,
                ],
                [
                    'key'   => 'receipt',
                    'title' => __('checkout.gateways.receipt'),
                    'icon'  => 'document-text',
                ],
            ]
        ];

        return success($data, __('checkout.payment_info_received'));
    }

    /**
     * ثبت نهایی سفارش
     */
    public function placeOrder(StoreOrderRequest $request, PlaceOrderAction $placeOrderAction): JsonResponse
    {
        try {
            $orderData = PlaceOrderData::fromRequest($request);

            $order = $placeOrderAction->execute(Auth::user(), $orderData);

            $order->load(['items.productVariant', 'address']);

            return success(
                new OrderResource($order),
                __('checkout.order_placed_reserved')
            );

        } catch (\Exception $e) {
            return error($e->getMessage(), [], 400);
        }
    }
}
