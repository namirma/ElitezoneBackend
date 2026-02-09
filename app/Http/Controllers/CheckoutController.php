<?php

namespace App\Http\Controllers;

use App\Actions\Order\PlaceOrderAction;
use App\DataTransferObjects\PlaceOrderData;
use App\Http\Requests\StoreOrderRequest;
use App\Http\Resources\OrderResource;
use App\Models\Cart;
use App\Models\Order;
use App\Models\WalletTransaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

class CheckoutController extends Controller
{
    public function __construct(
        protected PlaceOrderAction $placeOrderAction
    ) {}

    public function paymentInfo(Order $order): JsonResponse
    {
        Gate::authorize('view', $order);

        if ($order->payment_status === Order::PAYMENT_PAID) {
            return error(__('checkout.order_already_paid'), [], 400);
        }

        $user = Auth::user();
        $walletBalance = $user->wallet()->firstOrCreate([])->balance ?? 0;

        $gateways = [
            [
                'key'       => WalletTransaction::METHOD_GATEWAY,
                'title'     => __('checkout.gateways.internet'),
                'icon'      => 'card',
                'is_active' => true,
            ],
            [
                'key'        => 'wallet',
                'title'      => __('checkout.gateways.wallet'),
                'balance'    => (string) $walletBalance,
                'has_credit' => $walletBalance >= $order->final_amount,
                'icon'       => 'wallet',
                'is_active'  => true,
            ],
            [
                'key'       => WalletTransaction::METHOD_BANK_TRANSFER,
                'title'     => __('checkout.gateways.receipt'),
                'icon'      => 'document-text',
                'is_active' => true,
            ],
        ];

        $data = [
            'order_id'       => $order->id,
            'order_number'   => $order->order_number,
            'payable_amount' => (string) $order->final_amount,
            'items_count'    => $order->items()->count(),
            'created_at_p'   => verta($order->created_at)->format('Y/m/d H:i'),
            'gateways'       => $gateways,
        ];

        return success($data, __('checkout.payment_info_received'));
    }

    /**
     * ثبت نهایی سفارش (تبدیل سبد خرید به سفارش)
     */
    public function placeOrder(StoreOrderRequest $request): JsonResponse
    {
        $orderData = PlaceOrderData::fromRequest($request);
        $user = Auth::user();

        // 1. اطمینان از اینکه سبد خرید وجود دارد (برای جلوگیری از خطای منطقی)
        // ما اینجا سبد را چک می‌کنیم، اما به اکشن "کاربر" را پاس می‌دهیم (چون اکشن اینطور نوشته شده)
        $cart = Cart::with('items')
            ->where('user_id', $user->id)
            ->latest('updated_at')
            ->first();

        if (!$cart || $cart->items->isEmpty()) {
            return error(__('checkout.cart_is_empty'), [], 400);
        }

        // 2. رفرش کردن روابط کاربر تا اکشن (PlaceOrderAction) سبد قدیمی یا خالی نبیند
        // این خط حیاتی است:
        $user = $user->fresh(['cart.items.productVariant']);

        // 3. اجرای اکشن (ارسال User طبق انتظار سیستم)
        // اینجا قبلاً $cart بود که باعث ارور می‌شد
        $order = $this->placeOrderAction->execute($user, $orderData);

        // 4. بارگذاری روابط برای خروجی
        $order->load(['items.productVariant', 'address', 'shippingMethod']);

        return success(
            new OrderResource($order),
            __('checkout.order_placed_reserved')
        );
    }
}
