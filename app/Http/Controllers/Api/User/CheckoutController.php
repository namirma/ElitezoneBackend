<?php

namespace App\Http\Controllers\Api\User;

use App\Actions\Order\PlaceOrderAction;
use App\DataTransferObjects\Order\PlaceOrderData;
use App\Exceptions\InsufficientBalanceException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Order\ReserveFromCartRequest;
use App\Http\Requests\Order\StoreOrderRequest;
use App\Http\Resources\Order\OrderResource;
use App\Models\Cart;
use App\Models\Order;
use App\Models\SupplierProfile;
use App\Models\WalletTransaction;
use App\Services\Payment\PaymentBranch;
use App\Services\Payment\PaymentGatewayFactory;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
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

    /**
     * POST /checkout/pay — شاخه‌ی الف/ب (بند ۳.۳ و ۷.۲).
     *
     * **رکورد سفارش فقط اینجا و فقط وقتی ساخته می‌شود که پول در کیف پول نشسته
     * باشد.** ترتیبِ درونِ تراکنش عمدی است: ساختِ سفارش → کسر موجودی →
     * لِگ‌های مالی. اگر موجودی تمام شده باشد، کلِ تراکنش برمی‌گردد، هیچ سفارشی
     * نمی‌ماند و **پول در کیف پول کاربر باقی می‌ماند** — دقیقاً همان چیزی که
     * بند ۳.۳ برای حالتِ «پرداخت درگاه موفق شد ولی موجودی تمام شد» می‌خواهد.
     *
     * شاخه‌ی ب مستقیم از اینجا پرداخت نمی‌شود: پول درگاه **همیشه اول به کیف
     * پول** می‌رود (بند ۳.۳)، پس کلاینت اول کیف را شارژ می‌کند و بعد همین
     * اندپوینت را صدا می‌زند — آن وقت شاخه به الف تبدیل شده است.
     */
    public function pay(
        StoreOrderRequest $request,
        \App\Services\Payment\PaymentBranchResolver $branchResolver,
        \App\Services\Cart\CartCheckoutTotals $totals,
        \App\Actions\Financial\ProcessPreScalePaymentAction $settle,
        \App\Services\InventoryService $inventory,
    ): JsonResponse {
        $user = Auth::user();
        $cart = Cart::queryForUser($user->id)->with('items')->first();

        if (! $cart || $cart->items->isEmpty()) {
            return error(__('checkout.cart_is_empty'), [], 400);
        }

        $branch = $branchResolver->resolve($user, $totals->finalAmountFor($cart, $user));

        if ($branch->branch === PaymentBranch::RESERVE) {
            return error(__('checkout.reserve_only'), ['branch' => $branch->toArray()], 422);
        }

        if ($branch->branch === PaymentBranch::GATEWAY) {
            return error(__('checkout.top_up_wallet_first'), ['branch' => $branch->toArray()], 422);
        }

        return $this->createAndSettle(
            $request,
            $user,
            Order::STATUS_PROCESSING,
            fn (Order $order) => $settle->execute($order),
            $inventory,
            'checkout.order_paid',
        );
    }

    /**
     * POST /checkout/reserve — شاخه‌ی ج (بند ۳.۳ و ۳.۴).
     *
     * بیعانه از کیف پول کسر می‌شود و **موجودی همین‌جا کسر می‌گردد** — رزرو
     * برخلافِ سبد، واقعاً کالا را کنار می‌گذارد (بند ۳.۴).
     */
    public function reserve(
        ReserveFromCartRequest $request,
        \App\Services\Payment\PaymentBranchResolver $branchResolver,
        \App\Services\Payment\ReserveWarningResolver $warningResolver,
        \App\Services\Cart\CartCheckoutTotals $totals,
        \App\Services\Sales\SalesSettings $settings,
        \App\Services\Sales\ReserveDeadlineResolver $deadlineResolver,
        \App\Actions\Financial\ProcessReservationPaymentAction $settle,
        \App\Services\InventoryService $inventory,
    ): JsonResponse {
        $user = Auth::user();
        $cart = Cart::queryForUser($user->id)->with('items')->first();

        if (! $cart || $cart->items->isEmpty()) {
            return error(__('checkout.cart_is_empty'), [], 400);
        }

        // الحاقیه‌ی ۱، تغییر ۲: در پنجره‌ی کوتاه، تأییدِ صریحِ خریدار الزامی است.
        $warning = $warningResolver->forCart($cart);

        if ($warning['show'] && ! $request->boolean('confirm_short_window')) {
            return error(
                __('orders.reserve_warning.confirmation_required'),
                ['reserve_warning' => $warning],
                422
            );
        }

        // مهلتِ رزرو پیش از هر کارِ دیگری حل می‌شود: `null` یعنی پنجره‌ی قیمتِ
        // تأمین‌کننده منقضی شده و اصلاً چیزی برای قفل‌کردن وجود ندارد.
        //
        // این حالت واقعاً ممکن است — بند ۳.۲ سبدهای منتظر را زنده نگه می‌دارد،
        // پس کاربری با سبدِ منتظر می‌تواند دکمه‌ی رزرو را بزند. بدون این گارد و
        // با `reserve_auto_extend_minutes = 0`، مهلت برابرِ یک لحظه‌ی گذشته
        // می‌شد: رزروی که همان لحظه‌ی تولد مرده است.
        $supplierProfile = SupplierProfile::where(
            SupplierProfile::USER_ID,
            $cart->{Cart::SUPPLIER_USER_ID}
        )->first();

        $deadline = $deadlineResolver->resolve($supplierProfile);

        if ($deadline === null) {
            return error(__('supplier.prices_expired'), [], 422);
        }

        $reserveAmount = $settings->reserveValue();
        $branch = $branchResolver->resolve($user, $totals->finalAmountFor($cart, $user));

        // بیعانه هم از کیف پول پرداخت می‌شود؛ کسری باید اول شارژ شود.
        if ($branch->walletBalance < $reserveAmount) {
            return error(__('checkout.top_up_wallet_first'), ['branch' => $branch->toArray()], 422);
        }

        $graceHours = $deadlineResolver->graceHoursFor($supplierProfile);

        return $this->createAndSettle(
            $request,
            $user,
            Order::STATUS_RESERVED,
            fn (Order $order) => $settle->execute($order, $reserveAmount, $deadline, $graceHours),
            $inventory,
            'orders.reservation_successful',
        );
    }

    /**
     * ساختِ سفارش + کسر موجودی + تسویه، همه در **یک** تراکنش.
     *
     * ترتیب: سفارش → موجودی → پول. اگر هر کدام شکست بخورد هیچ‌کدام نمی‌مانند و
     * پولِ کاربر دست‌نخورده در کیف پول است.
     */
    private function createAndSettle(
        StoreOrderRequest $request,
        $user,
        string $initialStatus,
        callable $settle,
        \App\Services\InventoryService $inventory,
        string $messageKey,
    ): JsonResponse {
        $orderData = PlaceOrderData::fromRequest($request);

        try {
            $order = DB::transaction(function () use ($user, $orderData, $initialStatus, $settle, $inventory) {
                $order = $this->placeOrderAction->execute(
                    $user->fresh(['cart.items.productVariant']),
                    $orderData,
                    $initialStatus,
                );

                // موجودی در لحظه‌ی پرداخت/رزرو کسر می‌شود (بند ۶.۲)، و
                // `deductStockForOrder` خودش زیر قفل دوباره کفایت را می‌سنجد —
                // پس اگر بین نمایش سبد و پرداخت کالا تمام شده باشد، اینجا
                // می‌شکند و تراکنش برمی‌گردد.
                $inventory->deductStockForOrder($order);

                $settle($order);

                return $order;
            });
        } catch (InsufficientBalanceException $e) {
            return $this->balanceError($e);
        }

        $order->load(array_merge(Order::resourceRelations(), ['shippingMethod']));

        return success(new OrderResource($order), __($messageKey));
    }

    private function balanceError(InsufficientBalanceException $e): JsonResponse
    {
        return error(__('wallet.insufficient_balance'), [
            'available' => (string) $e->available,
            'required' => (string) $e->required,
        ], 422);
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
}
