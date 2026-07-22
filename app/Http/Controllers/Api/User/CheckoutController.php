<?php

namespace App\Http\Controllers\Api\User;

use App\Actions\Order\PlaceOrderAction;
use App\DataTransferObjects\Order\PlaceOrderData;
use App\Http\Controllers\Controller;
use App\Http\Requests\Order\StoreOrderRequest;
use App\Http\Resources\Order\OrderResource;
use App\Models\Cart;
use App\Models\Order;
use App\Models\WalletTransaction;
use App\Services\Marketing\CouponService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

class CheckoutController extends Controller
{
    public function __construct(
        protected PlaceOrderAction $placeOrderAction
    ) {}

    /**
     * پیش‌نمایش کد تخفیف قبل از ثبت سفارش — اعتبارسنجی + نوع و مقدار.
     * اعمال واقعی هنگام placeOrder (با coupon_code) و پرداخت انجام می‌شود.
     */
    public function couponPreview(Request $request, CouponService $couponService): JsonResponse
    {
        $validated = $request->validate(['code' => ['required', 'string', 'max:100']]);

        $coupon = $couponService->validateForCheckout(Auth::user(), $validated['code']);

        return success([
            'code' => $coupon->code,
            'type' => $coupon->type,
            'amount' => $coupon->amount,
            'expires_at' => $coupon->expires_at,
        ], __('coupons.preview_valid'));
    }

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
                'key' => WalletTransaction::METHOD_GATEWAY,
                'title' => __('checkout.gateways.internet'),
                'icon' => 'card',
                'is_active' => true,
            ],
            [
                'key' => 'wallet',
                'title' => __('checkout.gateways.wallet'),
                'balance' => (string) $walletBalance,
                'has_credit' => $walletBalance >= $order->final_amount,
                'icon' => 'wallet',
                'is_active' => true,
            ],
            [
                'key' => WalletTransaction::METHOD_BANK_TRANSFER,
                'title' => __('checkout.gateways.receipt'),
                'icon' => 'document-text',
                'is_active' => true,
            ],
        ];

        $data = [
            'order_id' => $order->id,
            'order_number' => $order->order_number,
            'payable_amount' => (string) $order->final_amount,
            'items_count' => $order->items()->count(),
            'created_at_p' => verta($order->created_at)->format('Y/m/d H:i'),
            'gateways' => $gateways,
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

        $cart = Cart::with('items')
            ->where(Cart::USER_ID, $user->id)
            ->latest('updated_at')
            ->first();

        if (! $cart || $cart->items->isEmpty()) {
            return error(__('checkout.cart_is_empty'), [], 400);
        }

        $user = $user->fresh(['cart.items.productVariant']);
        $order = $this->placeOrderAction->execute($user, $orderData);
        $order->load(array_merge(Order::resourceRelations(), [
            'shippingMethod',   // extra: برای صفحه تأیید سفارش
        ]));

        return success(
            new OrderResource($order),
            __('checkout.order_placed_reserved')
        );
    }
}
