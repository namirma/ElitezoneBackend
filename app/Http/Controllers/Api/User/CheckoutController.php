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
use App\Services\Payment\PaymentGatewayFactory;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;

class CheckoutController extends Controller
{
    public function __construct(
        protected PlaceOrderAction $placeOrderAction
    ) {}

    /**
     * پیش‌نمایش کد تخفیف قبل از ثبت سفارش — اعتبارسنجی + نوع و مقدار.
     * اعمال واقعی هنگام placeOrder (با coupon_code) و پرداخت انجام می‌شود.
     */
    public function couponPreview(\Illuminate\Http\Request $request, \App\Services\Marketing\CouponService $couponService): JsonResponse
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

    /**
     * GET /checkout/payment-options — خروجی `PaymentBranchResolver` (بند ۷.۲).
     *
     * **منبع واحد برای بک‌اند و فرانت** (بند ۶.۱): فرانت شاخه را از همین‌جا
     * می‌خواند و خودش دوباره حساب نمی‌کند. اگر هر دو طرف مستقل محاسبه کنند،
     * روی مرزها (`Final` دقیقاً برابرِ موجودی) دیر یا زود دو جواب می‌دهند و
     * کاربر دکمه‌ای می‌بیند که سرور ردش می‌کند.
     *
     * `reserve_warning` طبق الحاقیه‌ی ۱ اضافه شده — و همان مقدارِ `show` است
     * که `POST /checkout/reserve` را وادار به گرفتنِ `confirm_short_window`
     * می‌کند، تا هشدار صرفاً یک تزئینِ سمت کلاینت نباشد.
     */
    public function paymentOptions(
        \Illuminate\Http\Request $request,
        \App\Services\Cart\CartResolver $cartResolver,
        \App\Services\Payment\PaymentBranchResolver $branchResolver,
        \App\Services\Payment\ReserveWarningResolver $warningResolver,
        \App\Services\Cart\CartCheckoutTotals $totals,
    ): JsonResponse {
        $user = Auth::user();
        $cart = $cartResolver->resolve($request);

        if ($cart->items()->doesntExist()) {
            return error(__('checkout.cart_is_empty'), [], 400);
        }

        $finalAmount = $totals->finalAmountFor($cart, $user);
        $branch = $branchResolver->resolve($user, $finalAmount);

        return success(array_merge($branch->toArray(), [
            'reserve_warning' => $warningResolver->forCart($cart),
        ]), 'global.received_successfully');
    }

    public function paymentInfo(Order $order): JsonResponse
    {
        Gate::authorize('view', $order);

        if ($order->payment_status === Order::PAYMENT_PAID) {
            return error(__('checkout.order_already_paid'), [], 400);
        }

        $user = Auth::user();
        $walletBalance = $user->wallet()->firstOrCreate([])->balance ?? 0;

        // `is_active` واقعاً محاسبه می‌شود، نه `true`ِ ثابت.
        //
        // درگاهِ کارت بانکی هنوز مجوز نگرفته و `PasargadGateway::pay()` استثنا
        // پرتاب می‌کند؛ با مقدارِ ثابتِ قبلی، فرانت دکمه‌ای را فعال نشان می‌داد که
        // کلیک روی آن **قطعاً** شکست می‌خورد. حالا پاسخ همان چیزی را می‌گوید که
        // سرور واقعاً می‌تواند انجام دهد، و روزی که PASARGAD_* در .env پر شود این
        // روش بدون یک خط تغییر در کد فعال می‌شود.
        $gateways = [
            [
                'key' => WalletTransaction::METHOD_GATEWAY,
                'title' => __('checkout.gateways.internet'),
                'icon' => 'card',
                'is_active' => $this->onlineGatewayIsActive(),
            ],
            [
                'key' => 'wallet',
                'title' => __('checkout.gateways.wallet'),
                'balance' => (string) $walletBalance,
                // موجودیِ کافی سؤالِ جداست: `is_active` یعنی «این رِیل کار می‌کند»،
                // `has_credit` یعنی «این کاربر پولش را دارد».
                'has_credit' => $walletBalance >= $order->final_amount,
                'icon' => 'wallet',
                'is_active' => $this->gatewayIsConfigured('wallet'),
            ],
            [
                'key' => WalletTransaction::METHOD_BANK_TRANSFER,
                'title' => __('checkout.gateways.receipt'),
                'icon' => 'document-text',
                'is_active' => (bool) config('payment.bank_transfer_enabled', true),
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
     * آیا رِیلِ «پرداخت اینترنتی» (کارت بانکی) واقعاً قابل استفاده است؟
     * درگاهِ پشتِ این روش در `config/payment.online_gateway` تعیین می‌شود.
     */
    private function onlineGatewayIsActive(): bool
    {
        return $this->gatewayIsConfigured((string) config('payment.online_gateway', 'pasargad'));
    }

    /**
     * وضعیتِ پیکربندیِ یک driver. نامِ ناشناخته یعنی «غیرفعال» — یک تنظیمِ اشتباه
     * نباید باعثِ ۵۰۰ شدنِ صفحهٔ تسویه شود، فقط باید آن روش را خاموش نشان دهد.
     */
    private function gatewayIsConfigured(string $gatewayName): bool
    {
        try {
            return app(PaymentGatewayFactory::class)->make($gatewayName)->isConfigured();
        } catch (\Throwable $e) {
            Log::warning('paymentInfo: unknown or unresolvable payment gateway.', [
                'gateway' => $gatewayName,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * ثبت نهایی سفارش (تبدیل سبد خرید به سفارش)
     */
    public function placeOrder(StoreOrderRequest $request): JsonResponse
    {
        $orderData = PlaceOrderData::fromRequest($request);
        $user = Auth::user();

        // همان انتخابِ PlaceOrderAction — پیش‌بررسیِ «سبد خالی» نباید سبدی غیر از
        // سبدی که واقعاً سفارش از رویش ثبت می‌شود را نگاه کند.
        $cart = Cart::queryForUser($user->id)->with('items')->first();

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
