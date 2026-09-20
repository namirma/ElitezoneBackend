<?php

namespace App\Http\Controllers\Api\User;

use App\Actions\Financial\ProcessPreScalePaymentAction;
use App\Actions\Financial\ProcessReservationPaymentAction;
use App\Actions\Order\PlaceOrderAction;
use App\DataTransferObjects\Order\PlaceOrderData;
use App\Exceptions\InsufficientBalanceException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Order\ReserveFromCartRequest;
use App\Http\Requests\Order\StoreOrderRequest;
use App\Http\Resources\Cart\CartResource;
use App\Http\Resources\Order\OrderResource;
use App\Models\Cart;
use App\Models\Order;
use App\Models\SupplierProfile;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\Cart\CartCheckoutTotals;
use App\Services\Cart\CartPaymentLinkService;
use App\Services\Cart\CartResolver;
use App\Services\InventoryService;
use App\Services\Marketing\CouponService;
use App\Services\Payment\PaymentBranch;
use App\Services\Payment\PaymentBranchResolver;
use App\Services\Payment\PaymentGatewayFactory;
use App\Services\Payment\ReserveWarningResolver;
use App\Services\Sales\ReserveDeadlineResolver;
use App\Services\Sales\SalesSettings;
use App\Support\JalaliDate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
     *
     * اعتبارِ خودِ کد کافی نیست: کوپنی که تأمین‌کننده‌اش با سبدِ فعلی نمی‌خواند
     * (مثلاً کوپنِ الیت‌یاری روی سبدِ بی‌الیت‌یار) این‌جا «معتبر» می‌گفت، روی سبد
     * می‌نشست و تازه `pay` ردش می‌کرد (QA 2026-09-15، یافته‌ی ۱). حالا همان گاردِ
     * `payment-options`/`pay` را می‌زند — با همان پیام، همان لحظه. بدونِ سبد چیزی
     * برای سنجیدن نیست و فقط خودِ کد سنجیده می‌شود.
     */
    public function couponPreview(Request $request, CouponService $couponService, CartCheckoutTotals $totals): JsonResponse
    {
        $validated = $request->validate(['code' => ['required', 'string', 'max:100']]);

        $coupon = $couponService->validateForCheckout(Auth::user(), $validated['code']);

        $cart = Cart::queryForUser(Auth::id())->with('items.factory')->first();

        if ($cart !== null) {
            $totals->assertCouponFundableOn($coupon, $cart);
        }

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
        Request $request,
        CartResolver $cartResolver,
        PaymentBranchResolver $branchResolver,
        ReserveWarningResolver $warningResolver,
        CartCheckoutTotals $totals,
    ): JsonResponse {
        $user = Auth::user();
        $cart = $cartResolver->resolve($request);

        if ($cart->items()->doesntExist()) {
            return error(__('checkout.cart_is_empty'), [], 400);
        }

        // ⚠️ پایه‌ی تصمیم تا امروز **کرایه و مالیاتِ کرایه را نداشت**، در حالی
        // که سفارشی که ساخته می‌شود هر دو را دارد. یعنی `gateway_amount` به
        // اندازه‌ی کرایه کم بود و — بدتر — خودِ `branch` می‌توانست غلط باشد:
        // کسی که موجودی‌اش کالا را می‌پوشاند ولی کالا+کرایه را نه، شاخه‌ی
        // `wallet` می‌گرفت و دکمه‌ای می‌دید که قطعاً ۴۲۲ می‌گیرد.
        //
        // کرایه بدونِ مقصد معنا ندارد، پس آدرس اختیاری پذیرفته می‌شود و در
        // نبودش `carts.address_id` و بعد آدرسِ پیش‌فرضِ کاربر امتحان می‌شوند.
        $data = $request->validate([
            'address_id' => ['nullable', 'integer', 'exists:addresses,id'],
        ]);

        $breakdown = $totals->breakdownFor($cart, $user, $data['address_id'] ?? null);
        $branch = $branchResolver->resolve($user, $breakdown['final_amount']);

        return success(array_merge($branch->toArray(), [
            'reserve_warning' => $warningResolver->forCart($cart),
            // دو پرچمی که بدونشان فرانت دکمه‌ای نشان می‌داد که قطعاً شکست می‌خورد
            // (ممیزیِ ۲۰۲۶-۰۹-۱۲، باگ‌های ۱ و ۴):
            //  - رِیلِ «پرداخت اینترنتی» فقط وقتی هست که driverِ درگاه پیکربندی
            //    شده باشد — همان `isConfigured()` که `paymentInfo` می‌خواند؛ وگرنه
            //    `wallet/deposit` برای هر روشی جز فیش ۵۰۳ می‌دهد.
            //  - سبدی که برای کارفرما لینک شده از کیف پولِ الیت‌یار پرداخت
            //    نمی‌شود (`pay`/`reserve` ۴۲۲ می‌دهند)، پس فرانت باید کارتِ
            //    تسویه را ببندد.
            'online_gateway_active' => $this->onlineGatewayIsActive(),
            'is_for_beneficiary' => $cart->isForBeneficiary(),
            // قفلِ سبد (تصمیم مدیریت ۲۰۲۶-۰۹-۱۴): تا لینک باز است هر تغییرِ سبد
            // ۴۰۹ می‌گیرد و فرانت به‌جای دکمه‌ی تسویه، «لغو لینک پرداخت» را
            // نشان می‌دهد. `beneficiary_name` فقط وقتی هست که واقعاً نامی هست
            // (قاعده‌ی صداقت — نامِ خالی جعل نمی‌شود).
            'cart_locked' => $cart->isForBeneficiary(),
            'payment_link_sent_at' => $cart->{Cart::PAYMENT_LINK_SENT_AT}?->toIso8601String(),
            'beneficiary_name' => $cart->isForBeneficiary()
                ? (trim((string) $cart->beneficiary?->profile?->name) ?: null)
                : null,
            // اجزای پایه، تا فرانت مجبور نباشد خودش دوباره جمع بزند —
            // و `shipping_included` صریح می‌گوید کرایه شمرده شده یا نه.
            'breakdown' => [
                'goods_with_vat' => (string) $breakdown['gross'],
                'shipping_with_vat' => (string) $breakdown['shipping'],
                'coupon_discount' => (string) $breakdown['discount'],
                'shipping_included' => $breakdown['shipping_included'],
                // کوپنِ روی سبد که صفر شمرده شد، با دلیل — همان پیامی که `pay` می‌داد.
                'coupon_error' => $breakdown['coupon_error'],
            ],
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
        PaymentBranchResolver $branchResolver,
        CartCheckoutTotals $totals,
        ProcessPreScalePaymentAction $settle,
        InventoryService $inventory,
    ): JsonResponse {
        $user = Auth::user();
        $cart = Cart::queryForUser($user->id)->with('items')->first();

        if (! $cart || $cart->items->isEmpty()) {
            return error(__('checkout.cart_is_empty'), [], 400);
        }

        // تصمیم مدیریت (پرسش ۳، گزینه‌ی الف): وقتی سبد برای کارفرما بسته شده،
        // پرداخت **همیشه** از کیف پول اوست. بدون این گارد، الیت‌یار می‌توانست
        // همان سبد را از کیف پول خودش بپردازد و روی همان سفارش کمیسیون هم
        // بگیرد — دقیقاً نقصی که فاز ۶ برای بستنش آمده.
        if ($cart->isForBeneficiary()) {
            return error(__('checkout.elite_yar_cannot_pay_for_beneficiary'), [], 422);
        }

        // ‏`address_id` همان درخواست باید به پایه‌ی تصمیم برسد.
        //
        // بدونش `breakdownFor` به `carts.address_id` و بعد آدرسِ پیش‌فرض
        // برمی‌گشت، در حالی که `PlaceOrderAction` کرایه را برای آدرسی حساب
        // می‌کند که در **بدنه‌ی همین درخواست** آمده. خریداری که سرِ تسویه
        // مقصدی غیر از پیش‌فرض انتخاب می‌کرد روی کرایه‌ی اشتباه گیت می‌شد —
        // همان واگرایی که `payment-options` یک اندپوینت آن‌طرف‌تر بست.
        $addressId = $request->filled('address_id') ? $request->integer('address_id') : null;

        $branch = $branchResolver->resolve($user, $totals->finalAmountFor($cart, $user, $addressId));

        if ($branch->branch === PaymentBranch::RESERVE) {
            return error(__('checkout.reserve_only'), ['branch' => $branch->toArray()], 422);
        }

        if ($branch->branch === PaymentBranch::GATEWAY) {
            return error(__('checkout.top_up_wallet_first'), ['branch' => $branch->toArray()], 422);
        }

        return $this->createAndSettle(
            PlaceOrderData::fromRequest($request),
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
        PaymentBranchResolver $branchResolver,
        ReserveWarningResolver $warningResolver,
        CartCheckoutTotals $totals,
        SalesSettings $settings,
        ReserveDeadlineResolver $deadlineResolver,
        ProcessReservationPaymentAction $settle,
        InventoryService $inventory,
    ): JsonResponse {
        $user = Auth::user();
        $cart = Cart::queryForUser($user->id)->with('items')->first();

        if (! $cart || $cart->items->isEmpty()) {
            return error(__('checkout.cart_is_empty'), [], 400);
        }

        // همان گاردِ `pay()` — رزرو هم پرداخت است و از کیف پول کسر می‌کند.
        if ($cart->isForBeneficiary()) {
            return error(__('checkout.elite_yar_cannot_pay_for_beneficiary'), [], 422);
        }

        // ‏`address_id` همان درخواست باید به پایه‌ی تصمیم برسد.
        //
        // بدونش `breakdownFor` به `carts.address_id` و بعد آدرسِ پیش‌فرض
        // برمی‌گشت، در حالی که `PlaceOrderAction` کرایه را برای آدرسی حساب
        // می‌کند که در **بدنه‌ی همین درخواست** آمده. خریداری که سرِ تسویه
        // مقصدی غیر از پیش‌فرض انتخاب می‌کرد روی کرایه‌ی اشتباه گیت می‌شد —
        // همان واگرایی که `payment-options` یک اندپوینت آن‌طرف‌تر بست.
        $addressId = $request->filled('address_id') ? $request->integer('address_id') : null;

        $branch = $branchResolver->resolve($user, $totals->finalAmountFor($cart, $user, $addressId));

        // ⚠️ قرینه‌ی گاردهای `pay()` — و تا امروز غایب بود.
        //
        // ‏`PaymentBranch` خودش قاعده را می‌نویسد: «رزرو راهِ گریز از ناتوانیِ
        // پرداخت است، نه یک انتخابِ دلخواه که هر کسی بتواند با آن موجودی را قفل
        // کند» — و `reserveAvailable()` را هم برایش دارد. ولی این اندپوینت
        // هرگز صدایش نمی‌زد و تنها چیزی که می‌سنجید «آیا کیف پول بیعانه را
        // پوشش می‌دهد» بود. یعنی خریداری که در شاخه‌ی الف/ب است و باید کل مبلغ
        // را بپردازد، با صدا زدنِ همین اندپوینت سفارشِ `reserved` می‌گرفت:
        // موجودی کسر و کالا قفل می‌شد و باقیِ پول به بعد می‌افتاد — روی سفارشی
        // که اصلاً واجدِ شرایطِ رزرو نبود.
        //
        // بدونِ خطا و بدونِ لاگ، چون هر دو طرف با خودشان سازگار می‌ماندند.
        if (! $branch->reserveAvailable()) {
            return error(__('checkout.reserve_not_available'), ['branch' => $branch->toArray()], 422);
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

        // بیعانه هم از کیف پول پرداخت می‌شود؛ کسری باید اول شارژ شود.
        if ($branch->walletBalance < $reserveAmount) {
            return error(__('checkout.top_up_wallet_first'), ['branch' => $branch->toArray()], 422);
        }

        $graceHours = $deadlineResolver->graceHoursFor($supplierProfile);

        return $this->createAndSettle(
            PlaceOrderData::fromRequest($request),
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
    /**
     * POST /cart/payment-link — الیت‌یار سبد را برای کارفرما می‌فرستد (فاز ۶).
     *
     * اعتبارِ لینک ستون جداگانه‌ای ندارد: همان `carts.parked_until` است، یعنی
     * `reserve_time` تأمین‌کننده (تصمیم پرسش ۴). وقتی سبد منقضی شود لینک هم
     * می‌میرد و موجودی آزاد می‌شود (تصمیم پرسش ۵) — بدون هیچ کرونِ تازه‌ای.
     */
    public function sendPaymentLink(
        Request $request,
        CartPaymentLinkService $links,
    ): JsonResponse {
        $request->validate([
            'beneficiary_user_id' => ['required', 'integer', 'exists:users,id'],
        ]);

        $user = Auth::user();
        $cart = Cart::queryForUser($user->id)->with('items')->first();

        if (! $cart) {
            return error(__('checkout.cart_is_empty'), [], 400);
        }

        return success(
            $links->issue($user, $cart, (int) $request->integer('beneficiary_user_id')),
            __('checkout.payment_link_sent')
        );
    }

    /**
     * POST /checkout/payment-link/cancel — الیت‌یار لینکِ فرستاده‌شده را لغو می‌کند.
     *
     * بی‌اثر روی سبدی که لینکِ بازی ندارد (۲۰۰ با `cancelled: false`) — دوبار
     * زدنِ دکمه یا لغو پس از پرداختِ کارفرما خطا نیست. سبد همان سبدِ خودِ
     * کاربر است، پس کسی جز فرستنده‌ی لینک به آن نمی‌رسد.
     */
    public function cancelPaymentLink(CartPaymentLinkService $links): JsonResponse
    {
        $user = Auth::user();
        $cart = Cart::queryForUser($user->id)->first();

        $cancelled = $cart !== null && $links->cancel($user, $cart);

        return success(
            ['cancelled' => $cancelled],
            $cancelled ? __('checkout.payment_link_cancelled_by_you') : __('checkout.cart_has_no_payment_link')
        );
    }

    /**
     * GET /checkout/pay-for/{cart} — کارفرما سبدی را که الیت‌یار بسته می‌بیند.
     *
     * شاخه‌ی پرداخت در برابر کیف پولِ **کارفرما** حساب می‌شود، نه الیت‌یار —
     * او کسی است که قرار است بپردازد.
     */
    public function showPaymentLink(
        Cart $cart,
        CartPaymentLinkService $links,
        PaymentBranchResolver $branchResolver,
        CartCheckoutTotals $totals,
    ): JsonResponse {
        $employer = Auth::user();
        $links->assertPayableBy($cart, $employer);

        // عمداً از `CartResponseBuilder::build()` استفاده نمی‌شود.
        //
        // آن متد سبد را با نقشِ قیمتیِ **کاربرِ درخواستِ جاری** دوباره قیمت
        // می‌زند و نتیجه را روی ردیف‌ها می‌نویسد. اینجا کاربرِ جاری کارفرماست،
        // پس صدازدنش قیمتِ سبدی را که الیت‌یار بسته بی‌صدا عوض می‌کرد — یعنی
        // کارفرما عددی غیر از آنچه در لینک به او نشان داده شده می‌پرداخت.
        $cart->loadMissing(['items.productVariant.product', 'user']);

        $cartOwner = $cart->user;

        return success([
            'cart' => new CartResource($cart),
            'final_amount' => (string) $totals->finalAmountFor($cart, $cartOwner),
            'payment' => $branchResolver->resolve($employer, $totals->finalAmountFor($cart, $cartOwner))->toArray(),
            'expires_at' => $cart->{Cart::PARKED_UNTIL}?->toIso8601String(),
            'prepared_by' => [
                'id' => $cartOwner?->id,
                // getAttribute — larastan بدون ide-helper این ستون را نمی‌شناسد.
                // همان اشتباه: نام در `user_profiles.name` است نه `users.name`.
                'name' => $cartOwner?->profile?->name,
            ],
        ], 'global.received_successfully');
    }

    /**
     * POST /checkout/pay-for/{cart} — کارفرما پرداخت می‌کند و سفارش ساخته می‌شود.
     *
     * بدنه‌ای گرفته نمی‌شود: آدرس، روش ارسال و کد تخفیف را الیت‌یار انتخاب کرده
     * و روی سبد نشسته‌اند (`PlaceOrderData::fromCart`). سفارش با ثبت‌کننده‌ی
     * الیت‌یار، ذی‌نفعِ کارفرما و **پرداخت‌کننده‌ی کارفرما** ساخته می‌شود.
     */
    public function payForBeneficiary(
        Request $request,
        Cart $cart,
        CartPaymentLinkService $links,
        PaymentBranchResolver $branchResolver,
        CartCheckoutTotals $totals,
        ProcessPreScalePaymentAction $settle,
        InventoryService $inventory,
    ): JsonResponse {
        $employer = Auth::user();
        $links->assertPayableBy($cart, $employer);

        $cartOwner = $cart->user;

        if (! $cartOwner) {
            return error(__('checkout.cart_is_empty'), [], 400);
        }

        $branch = $branchResolver->resolve($employer, $totals->finalAmountFor($cart, $cartOwner));

        if ($branch->branch !== PaymentBranch::WALLET) {
            return error(__('checkout.top_up_wallet_first'), ['branch' => $branch->toArray()], 422);
        }

        return $this->createAndSettle(
            PlaceOrderData::fromCart($cart, $request),
            $cartOwner,
            Order::STATUS_PROCESSING,
            fn (Order $order) => $settle->execute($order),
            $inventory,
            'checkout.order_paid',
            payer: $employer,
        );
    }

    private function createAndSettle(
        PlaceOrderData $orderData,
        $user,
        string $initialStatus,
        callable $settle,
        InventoryService $inventory,
        string $messageKey,
        ?User $payer = null,
    ): JsonResponse {
        try {
            $order = DB::transaction(function () use ($user, $orderData, $initialStatus, $settle, $inventory, $payer) {
                $order = $this->placeOrderAction->execute(
                    $user->fresh(['cart.items.productVariant']),
                    $orderData,
                    $initialStatus,
                    $payer,
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
            'created_at_p' => JalaliDate::format($order->created_at),
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
     * نباید باعثِ ۵۰۰ شدنِ صفحه‌ی تسویه شود، فقط باید آن روش را خاموش نشان دهد.
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
