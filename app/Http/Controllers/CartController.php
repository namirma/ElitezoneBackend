<?php

namespace App\Http\Controllers;

use App\Actions\Cart\AddToCartAction;
use App\Actions\Cart\RemoveCartItemAction;
use App\Actions\Cart\SyncCartFiltersAction;
use App\Actions\Cart\UpdateCartItemAction;
use App\DataTransferObjects\Pricing\PricingContextData;
use App\Http\Requests\AddToCartRequest;
use App\Http\Requests\UpdateCartFiltersRequest;
use App\Http\Requests\UpdateCartItemRequest;
use App\Http\Requests\UpdateCustomerTypeRequest;
use App\Http\Resources\CartResource;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\CustomerType;
use App\Models\PriceFilterCategory;
use App\Services\Pricing\CartPricingCalculator;
use App\Services\PricingRoleResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class CartController extends Controller
{
    public function __construct(
        private readonly CartPricingCalculator $cartPricing,
        private readonly PricingRoleResolver $roleResolver,
    ) {}

    // ---------------------------------------------------------------------
    // Public Endpoints
    // ---------------------------------------------------------------------

    /**
     * دریافت سبد خرید فعلی + محاسبه قیمت‌ها
     */
    public function getCart(Request $request): JsonResponse
    {
        $cart = $this->resolveCart($request);

        return $this->respondWithCart($request, $cart);
    }

    /**
     * افزودن آیتم به سبد
     */
    public function addToCart(AddToCartRequest $request, AddToCartAction $action): JsonResponse
    {
        $cart = $this->resolveCart($request);

        // تمام لاجیک‌های چک کردن لیمیت، موجودی و مرج کردن در اکشن انجام می‌شود
        $action->execute($cart, $request->validated());

        return $this->respondWithCart($request, $cart);
    }

    /**
     * بروزرسانی تعداد یا ویژگی‌های یک آیتم
     */
    public function updateCartItem(
        UpdateCartItemRequest $request,
        CartItem $item,
        UpdateCartItemAction $action
    ): JsonResponse {
        $cart = $this->resolveCart($request);

        // امنیت: مطمئن شویم آیتم مال همین سبد است
        if ($item->cart_id !== $cart->id) {
            return error('global.notFound', [], 404);
        }

        $action->execute($item, $request->validated());

        return $this->respondWithCart($request, $cart);
    }

    /**
     * حذف یک آیتم
     */
    public function removeCartItem(
        Request $request,
        CartItem $item,
        RemoveCartItemAction $action
    ): JsonResponse {
        $cart = $this->resolveCart($request);

        if ($item->cart_id !== $cart->id) {
            return error('global.notFound', [], 404);
        }

        $action->execute($item);

        return $this->respondWithCart($request, $cart);
    }

    /**
     * تغییر فیلترهای قیمت (نقد/اقساط و ...)
     */
    public function updateCartFilters(
        UpdateCartFiltersRequest $request,
        SyncCartFiltersAction $action
    ): JsonResponse {
        $cart = $this->resolveCart($request);

        $action->execute($cart, $request->validated('filter_categories', []));

        return $this->respondWithCart($request, $cart);
    }

    /**
     * تغییر نوع مشتری (حقیقی/حقوقی/...)
     */
    public function updateCustomerType(UpdateCustomerTypeRequest $request): JsonResponse
    {
        $cart = $this->resolveCart($request);

        $cart->update(['customer_type_id' => $request->validated('customer_type_id')]);

        return $this->respondWithCart($request, $cart);
    }

    // ---------------------------------------------------------------------
    // Core Helpers
    // ---------------------------------------------------------------------

    /**
     * متد مرکزی برای ساخت پاسخ استاندارد (Cart Resource + Calculated Totals)
     */
    private function respondWithCart(Request $request, Cart $cart): JsonResponse
    {
        $cart->load([
            'items.productVariant.product.images',
            'items.productVariant.variantAttributes.attributeType',
            'items.productVariant.product.orderUnits',
            'items.factory',
            'items.factoryLocation',
            'items.shippingMethod',
            'customerType',
            'badge',
            'priceFilterCategories',
        ]);

        // محاسبه قیمت
        $ctx = $this->buildPricingContext($cart);
        $result = $this->cartPricing->calculate($cart, $ctx);

        // تزریق نتایج محاسباتی به آیتم‌ها (اختیاری، جهت نمایش در Resource)
        $this->applyPricingResultToCartItems($cart, $result);

        $response = success([
            'cart' => new CartResource($cart),
            'totals' => [
                'subtotal' => (string) $result->subtotal,
                'vat_amount' => (string) $result->vatAmount,
                'total_variant_discount' => (string) $result->totalVariantDiscount,
                'cart_level_offer_discount' => (string) $result->offerDiscount,
                'total' => (string) $result->total,
            ],
        ], 'global.received_successfully');

        if (! $cart->user_id) {
            $response->cookie('cart_session_id', $cart->session_id, 60 * 24 * 30);
        }

        return $response;
    }

    /**
     * پیدا کردن سبد خرید (User یا Guest)
     */
    private function resolveCart(Request $request): Cart
    {
        $userId = Auth::id();

        if ($userId) {
            $cart = Cart::firstOrCreate([Cart::USER_ID => $userId]);
        } else {
            $sessionId = $request->cookie('cart_session_id', (string) Str::uuid());
            $cart = Cart::firstOrCreate(
                [Cart::SESSION_ID => $sessionId],
                [Cart::USER_ID => null]
            );
        }

        $this->ensureCartContext($cart);

        return $cart;
    }

    private function ensureCartContext(Cart $cart): void
    {
        $activeCode = $this->activeCode();

        // 1. تنظیم نوع مشتری پیش‌فرض اگر ندارد
        if (! $cart->customer_type_id) {
            $defaultType = Cache::remember('customer_type.default', 3600, fn () => CustomerType::where(CustomerType::CODE, 'employer_without_consultant')->first()
            );
            if ($defaultType) {
                $cart->customer_type_id = $defaultType->id;
                $cart->save();
            }
        }

        // 2. همگام‌سازی با Active Code (اگر کاربر لاگین کرده و نقشش عوض شده)
        $ct = Cache::remember("customer_type.{$activeCode}", 3600, fn () => CustomerType::where(CustomerType::CODE, $activeCode)->first()
        );
        if ($ct && $cart->customer_type_id !== $ct->id) {
            $cart->update([Cart::CUSTOMER_TYPE_ID => $ct->id]);
        }

        // 3. همگام‌سازی نشان (Badge) کاربر با سبد خرید [بخش جدید]
        if (Auth::check()) {
            $user = Auth::user();

            // ابتدا از ستون badge_id خود کاربر می‌خوانیم
            $userBadgeId = $user->badge_id;

            // اگر ستون badge_id خالی بود اما در جدول واسط رکورد داشت (فال‌بک)
            if (! $userBadgeId) {
                // آخرین بج تخصیص داده شده را می‌گیریم
                $latestBadge = $user->badges()->latest('badge_user.created_at')->first();
                $userBadgeId = $latestBadge?->id;
            }

            // اگر کاربر بج دارد و سبد خرید ست نشده (یا متفاوت است)، آپدیت کن
            if ($userBadgeId && $cart->badge_id !== $userBadgeId) {
                $cart->update([Cart::BADGE_ID => $userBadgeId]);
            }
        }
    }

    private function buildPricingContext(Cart $cart): PricingContextData
    {
        $activeCode = $this->activeCode();

        // اگر فیلتر خاصی برای نقش فعال اجباری است آن را بگیر، وگرنه فیلترهای انتخابی کاربر
        $pfId = PriceFilterCategory::where(PriceFilterCategory::CODE, $activeCode)->value('id');
        $filterIds = $pfId ? [$pfId] : $cart->priceFilterCategories->pluck('id')->all();

        return new PricingContextData(
            activeCode: $activeCode,
            customerType: $cart->customerType,
            badge: $cart->badge,
            filterCategoryIds: $filterIds,
            withVat: true,
            offerBasis: 'weight',
        );
    }

    private function activeCode(): string
    {
        if (app()->bound('active_price_filter_category_code')) {
            return (string) app('active_price_filter_category_code');
        }

        if (Auth::check()) {
            return $this->roleResolver->resolve(Auth::user());
        }

        return 'employer_without_consultant';
    }

    /**
     * نگاشت نتایج محاسبه قیمت به مدل‌های کارت آیتم (برای استفاده در ریسورس)
     */
    private function applyPricingResultToCartItems(Cart $cart, $result): void
    {
        if (! isset($result->items)) {
            return;
        }

        foreach ($cart->items as $item) {
            $lineResult = $result->items->get($item->id);
            if ($lineResult) {
                $item->setRelation('pricing_result', $lineResult);
            }
        }
    }
}
