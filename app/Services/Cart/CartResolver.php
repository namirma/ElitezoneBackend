<?php

namespace App\Services\Cart;

use App\Exceptions\BusinessException;
use App\Models\Cart;
use App\Models\CustomerType;
use App\Services\PricingRoleResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Resolves the current Cart for either an authenticated user or a guest
 * (via cookie session_id), then syncs its customer type and badge to match
 * the user's role / assigned badge.
 *
 * Extracted from CartController so the same flow can be reused by checkout
 * and any future entry points.
 */
class CartResolver
{
    public function __construct(
        private readonly PricingRoleResolver $roleResolver,
        private readonly CartPaymentLinkService $paymentLinks,
        private readonly CartParkService $park,
    ) {}

    /**
     * Resolve the request's cart, creating it if needed, and ensure its
     * customer_type_id and badge_id are aligned with the current user.
     */
    public function resolve(Request $request): Cart
    {
        $cart = $this->loadOrCreateCart($request);
        $this->syncContext($cart);

        // قفلِ تأمین‌کننده روی سبدِ خالی معنایی ندارد و فقط خریدِ بعدی را می‌بندد
        // (QA 2026-09-15، سبد ۱۴۹: `releaseIfEmpty` فقط در حذفِ آیتم صدا زده می‌شد).
        // ثبتِ سفارش و انقضا خودِ ردیفِ سبد را پاک می‌کنند و قفلی جا نمی‌گذارند؛
        // آنچه قفل را با سبدِ خالی جا می‌گذارد، حذفِ آیتم‌ها از **بیرونِ** اکشن‌های
        // سبد است — cascade از حذفِ واریانت/کارخانه، یا ویرایشِ مستقیمِ دیتابیس.
        // هر resolve، اگر قفل هست و آیتمی نیست، آزادش می‌کند (سبدِ مهمان هم).
        // برای سبدِ کاربر این پیش از ادغامِ سبدِ مهمان هم در `loadOrCreateCart`
        // اجرا می‌شود — ببینید چرا.
        $this->releaseStaleLock($cart);

        return $cart;
    }

    private function releaseStaleLock(Cart $cart): void
    {
        // مهلتِ پارک هم مثلِ قفل: سبدی که با کارخانه‌ی بی‌تأمین‌کننده پارک شده ولی
        // قفل نگرفته، اگر خالی شود `parked_until` را جا می‌گذارد و انقضا یک
        // «سبدِ رهاشده»ی بی‌قلم اسنپ‌شات می‌گیرد.
        if ($cart->{Cart::SUPPLIER_USER_ID} !== null || $cart->{Cart::PARKED_UNTIL} !== null) {
            $this->park->releaseIfEmpty($cart);
        }
    }

    /**
     * همان `resolve()` برای مسیرهایی که سبد را **تغییر** می‌دهند.
     *
     * قفلِ «لینکِ پرداختِ باز» یک‌جا این‌جاست، نه در هر اکشن: شش اندپوینتِ
     * سبد (افزودن/ویرایش/حذفِ آیتم، فیلتر، نوعِ مشتری، کوپن) همه از همین رد
     * می‌شوند، پس هفتمی که فردا اضافه شود نمی‌تواند گارد را جا بیندازد.
     * خواندن (`getCart`, `pre-invoice`, `shipping-quote`) همچنان `resolve()` است.
     *
     * @throws BusinessException ۴۰۹ وقتی لینکِ پرداخت باز است
     */
    public function resolveForMutation(Request $request): Cart
    {
        $cart = $this->resolve($request);
        $this->paymentLinks->assertMutable($cart);

        return $cart;
    }

    /**
     * Active price-filter category code — driven by middleware binding,
     * else by the user's resolved pricing role, else a guest default.
     */
    public function activeCode(): string
    {
        if (app()->bound('active_price_filter_category_code')) {
            return (string) app('active_price_filter_category_code');
        }

        // memoize در طول یک request — PricingRoleResolver هر بار fresh(['roles','employer'])
        // می‌زند و activeCode در یک درخواست سبد چند بار صدا زده می‌شود.
        if (app()->bound('pricing.resolved_active_code')) {
            return (string) app('pricing.resolved_active_code');
        }

        $code = Auth::check()
            ? $this->roleResolver->resolve(Auth::user())
            : 'employer_without_elite_yar';

        app()->instance('pricing.resolved_active_code', $code);

        return $code;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Internals
    // ─────────────────────────────────────────────────────────────────────────

    private function loadOrCreateCart(Request $request): Cart
    {
        $userId = Auth::id();

        if ($userId) {
            // Cart::resolveForUser تنها راهِ انتخابِ سبدِ کاربر است — PlaceOrderAction
            // هم از همان استفاده می‌کند تا سبدی که نمایش داده می‌شود همان سبدی باشد
            // که سفارش از رویش ثبت می‌شود.
            $cart = Cart::resolveForUser($userId);

            // قفلِ کهنه‌ی سبدِ خالی باید **پیش از** ادغامِ سبدِ مهمان برود: وگرنه
            // اقلامِ مهمان (که می‌توانند مالِ تأمین‌کننده‌ی دیگری باشند) زیرِ قفلِ
            // تأمین‌کننده‌ی قبلی می‌نشینند، `releaseIfEmpty` دیگر سبد را خالی
            // نمی‌بیند، و همان قفلِ کهنه با اقلامِ بیگانه می‌ماند.
            $this->releaseStaleLock($cart);
            $this->mergeGuestCart($request, $cart);

            return $cart;
        }

        $sessionId = $request->cookie('cart_session_id', (string) Str::uuid());

        // whereNull(user_id) در شرطِ جستجو: cart_session_id یک کوکیِ کاملاً تحتِ کنترلِ
        // کلاینت است، پس هرگز نباید بتواند به سبدِ یک کاربرِ لاگین‌شده resolve شود.
        // امروز سبدِ کاربر session_id = NULL دارد و عملاً قابل تطبیق نیست، ولی این
        // ثابت‌ماندن یک فرضِ ضمنی بود نه یک گارد — حالا صریح است.
        $guestCart = Cart::query()
            ->where(Cart::SESSION_ID, $sessionId)
            ->whereNull(Cart::USER_ID)
            ->first();

        return $guestCart ?? Cart::create([
            Cart::SESSION_ID => $sessionId,
            Cart::USER_ID => null,
        ]);
    }

    /**
     * آیتم‌های سبد مهمان (کوکی cart_session_id) بعد از لاگین به سبد کاربر منتقل
     * می‌شوند — قبلاً سبد مهمان orphan می‌شد و انتخاب‌های کاربر از بین می‌رفت.
     * اگر همان (واریانت، کارخانه، مبدأ) در سبد کاربر باشد، آیتم کاربر حفظ می‌شود.
     */
    private function mergeGuestCart(Request $request, Cart $userCart): void
    {
        $sessionId = $request->cookie('cart_session_id');
        if (! $sessionId) {
            return;
        }

        $guestCart = Cart::where(Cart::SESSION_ID, $sessionId)
            ->whereNull(Cart::USER_ID)
            ->where('id', '!=', $userCart->id)
            ->first();

        if (! $guestCart) {
            return;
        }

        foreach ($guestCart->items as $guestItem) {
            $alreadyInUserCart = $userCart->items()
                ->where('product_variant_id', $guestItem->product_variant_id)
                ->where('factory_id', $guestItem->factory_id)
                ->where('factory_location_id', $guestItem->factory_location_id)
                ->exists();

            if ($alreadyInUserCart) {
                $guestItem->delete();

                continue;
            }

            $guestItem->update(['cart_id' => $userCart->id]);
        }

        $guestCart->delete();

        // سبدی که حالا قلم دارد باید قفلِ تأمین‌کننده‌ی همان اقلام را داشته باشد —
        // همان کاری که `AddToCartAction` برای قلمِ اول می‌کند. بدونِ این، سبدِ
        // ادغام‌شده بی‌قفل می‌ماند و قلمِ تأمین‌کننده‌ی دیگری تا لحظه‌ی ثبت رد نمی‌شد.
        if ($userCart->{Cart::SUPPLIER_USER_ID} === null) {
            $supplierId = $userCart->load('items.factory')->items->first()?->factory?->supplier_user_id;
            $this->park->lockSupplier($userCart, $supplierId !== null ? (int) $supplierId : null);
        }
    }

    private function syncContext(Cart $cart): void
    {
        $activeCode = $this->activeCode();

        // 1. تنظیم نوع مشتری پیش‌فرض اگر ندارد
        if (! $cart->customer_type_id) {
            $defaultType = Cache::remember(
                'customer_type.default',
                3600,
                fn () => CustomerType::where(CustomerType::CODE, 'employer_without_elite_yar')->first(),
            );
            if ($defaultType) {
                $cart->customer_type_id = $defaultType->id;
                $cart->save();
            }
        }

        // 2. همگام‌سازی با Active Code
        $ct = Cache::remember(
            "customer_type.{$activeCode}",
            3600,
            fn () => CustomerType::where(CustomerType::CODE, $activeCode)->first(),
        );
        if ($ct && $cart->customer_type_id !== $ct->id) {
            $cart->update([Cart::CUSTOMER_TYPE_ID => $ct->id]);
        }

        // 3. همگام‌سازی نشان (Badge) کاربر با سبد خرید
        if (Auth::check()) {
            $user = Auth::user();

            $userBadgeId = $user->badge_id
                ?? $user->badges()->latest('badge_user.created_at')->first()?->id;

            if ($userBadgeId && $cart->badge_id !== $userBadgeId) {
                $cart->update([Cart::BADGE_ID => $userBadgeId]);
            }
        }
    }
}
