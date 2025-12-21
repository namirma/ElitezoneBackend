<?php

namespace App\Http\Controllers;

use App\Actions\Order\CalculateOrderEstimateAction;
use App\Actions\Product\FilterProductsAction;
use App\Actions\Product\FilterProductVariantsAction;
use App\Http\Requests\OrderPriceRequest;
use App\Http\Requests\ProductIndexRequest;
use App\Http\Requests\ProductVariantFilterRequest;
use App\Http\Requests\ProductVariantPriceRequest;
use App\Http\Resources\AttributeTypeResource;
use App\Http\Resources\FactoryResource;
use App\Http\Resources\OfferResource;
use App\Http\Resources\ProductDetailResource;
use App\Http\Resources\ProductResource;
use App\Http\Resources\ProductVariantResource;
use App\Models\Attribute;
use App\Models\AttributeType;
use App\Models\Badge;
use App\Models\Cart;
use App\Models\CustomerType;
use App\Models\Factory;
use App\Models\FactoryLocation;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Repositories\Contracts\ProductRepositoryInterface;
use App\Services\PriceCalculationService;
use App\Services\PricingRoleResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class ProductController extends Controller
{
    public function __construct(
        private readonly PricingRoleResolver $roleResolver,
        private readonly ProductRepositoryInterface $productRepo,
        private readonly PriceCalculationService $priceService
    ) {}


    public function index(ProductIndexRequest $request, FilterProductsAction $action): JsonResponse
    {
        $activeCode = $this->resolveActiveCode();

        $filterData = $request->toDto($activeCode);

        $paginator = $action->execute($filterData);

        return success(
            ProductResource::collection($paginator)->response()->getData(true),
            'global.received_successfully'
        );
    }

    /**
     * نمایش جزئیات محصول + ثبت هوشمند بازدید
     */
    public function show(Product $product): JsonResponse
    {
        // ۱. بارگذاری روابط (Eager Loading) برای جلوگیری از کوئری‌های اضافی
        $product->loadMissing([
            'category',
            'images',
            'videos',
            'orderUnits',
            'offers',
            'aggregateLimitOrderUnit'
        ]);

        // ۲. ثبت بازدید و داده‌های تحلیلی (Async)
        \App\Jobs\LogProductView::dispatch(
            productId: $product->id,
            userId: Auth::id(),
            ipAddress: request()->ip(),
            userAgent: request()->userAgent(),
            sessionId: request()->cookie('cart_session_id')
        )->afterResponse();

        // ۳. دریافت لیست کارخانه‌ها
        $factories = $this->productRepo->getProductFactories($product->id);

        // ۴. بازگشت پاسخ
        return success([
            'product'   => new ProductDetailResource($product),
            'factories' => FactoryResource::collection($factories),
        ], 'global.received_successfully');
    }

    /**
     * لیست واریانت‌های یک محصول (برای انتخاب سایز/رنگ)
     */
    public function variants(ProductVariantFilterRequest $request, Product $product, FilterProductVariantsAction $action): JsonResponse
    {
        $activeCode = $this->resolveActiveCode();

        $paginator = $action->execute(
            product: $product,
            filters: $request->validated(),
            roleCode: $activeCode
        );

        return success(
            ProductVariantResource::collection($paginator)->response()->getData(true),
            'global.received_successfully'
        );
    }

    /**
     * دریافت ویژگی‌های قابل فیلتر برای یک محصول (مثل لیست رنگ‌ها)
     */
    public function filters(Product $product): JsonResponse
    {
        $attributeTypes = AttributeType::whereHas('attributes', function ($q) use ($product) {
            $q->whereHas('productVariants', function ($q2) use ($product) {
                $q2->where('product_id', $product->id);
            });
        })
            ->with(['attributes' => function ($q) use ($product) {
                $q->whereHas('productVariants', function ($q2) use ($product) {
                    $q2->where('product_id', $product->id);
                });
            }])
            ->get();

        return success(AttributeTypeResource::collection($attributeTypes), 'global.received_successfully');
    }

    /**
     * محاسبه قیمت دقیق یک واریانت خاص
     */
    public function getVariantPrice(ProductVariantPriceRequest $request): JsonResponse
    {
        $data = $request->validated();
        $this->handleCartSession($request);

        $variant  = ProductVariant::findOrFail($data['variant_id']);
        $factory  = Factory::findOrFail($data['factory_id']);
        $location = FactoryLocation::findOrFail($data['location_id']);

        $activeCode   = $this->resolveActiveCode();
        $badge        = $this->getCartBadge();
        $customerType = CustomerType::where(CustomerType::CODE, $activeCode)->first();

        $priceDto = $this->priceService->calculatePrice(
            variant: $variant,
            factory: $factory,
            location: $location,
            customerType: $customerType,
            badge: $badge,
            filterCategoryIds: null,
            withVAT: (bool)($data['with_vat'] ?? false),
            filterCategoryCode: $activeCode
        );

        return success($priceDto->toArray(), 'global.received_successfully');
    }

    /**
     * محاسبه پیش‌فاکتور نهایی سبد خرید/سفارش
     */
    public function calculateOrderPrice(OrderPriceRequest $request, CalculateOrderEstimateAction $action): JsonResponse
    {
        $activeCode = $this->resolveActiveCode();
        $badge      = !empty($request->badge_id) ? Badge::find($request->badge_id) : $this->getCartBadge();

        $result = $action->execute($request->validated(), $activeCode, $badge);

        return success([
            'unit_price'         => (string)$result['unit_dto']->finalPrice,
            'unit_price_details' => $result['unit_dto']->toArray(),
            'order_details'      => $result['order_details'],
            'offers'             => OfferResource::collection($result['offers']),
            ...$result['financials']
        ], 'global.received_successfully');
    }

    // =========================================================================
    //  Private Helpers
    // =========================================================================

    private function resolveActiveCode(): string
    {
        if (!Auth::check()) return 'employer_without_consultant';
        return $this->roleResolver->resolve(Auth::user());
    }

    private function handleCartSession($request): void
    {
        if (Auth::check()) {
            Cart::firstOrCreate([Cart::USER_ID => Auth::id()]);
        } else {
            $sessionId = $request->cookie('cart_session_id', Str::uuid()->toString());
            if (!Cart::where(Cart::SESSION_ID, $sessionId)->exists()) {
                $cart = Cart::create([Cart::SESSION_ID => $sessionId, Cart::USER_ID => null]);
                cookie()->queue('cart_session_id', $cart->session_id, 60 * 24 * 30);
            }
        }
    }

    private function getCartBadge(): ?Badge
    {
        $query = Auth::check()
            ? Cart::where(Cart::USER_ID, Auth::id())
            : Cart::where(Cart::SESSION_ID, request()->cookie('cart_session_id'));

        $cart = $query->first();
        return $cart && $cart->badge_id ? Badge::find($cart->badge_id) : null;
    }
}
