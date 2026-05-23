<?php

namespace App\Services\Cart;

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
    ) {}

    /**
     * Resolve the request's cart, creating it if needed, and ensure its
     * customer_type_id and badge_id are aligned with the current user.
     */
    public function resolve(Request $request): Cart
    {
        $cart = $this->loadOrCreateCart($request);
        $this->syncContext($cart);

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

        if (Auth::check()) {
            return $this->roleResolver->resolve(Auth::user());
        }

        return 'employer_without_consultant';
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Internals
    // ─────────────────────────────────────────────────────────────────────────

    private function loadOrCreateCart(Request $request): Cart
    {
        $userId = Auth::id();

        if ($userId) {
            return Cart::firstOrCreate([Cart::USER_ID => $userId]);
        }

        $sessionId = $request->cookie('cart_session_id', (string) Str::uuid());

        return Cart::firstOrCreate(
            [Cart::SESSION_ID => $sessionId],
            [Cart::USER_ID    => null],
        );
    }

    private function syncContext(Cart $cart): void
    {
        $activeCode = $this->activeCode();

        // 1. تنظیم نوع مشتری پیش‌فرض اگر ندارد
        if (! $cart->customer_type_id) {
            $defaultType = Cache::remember(
                'customer_type.default',
                3600,
                fn () => CustomerType::where(CustomerType::CODE, 'employer_without_consultant')->first(),
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
