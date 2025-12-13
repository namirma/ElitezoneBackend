<?php

namespace App\Http\Controllers;

use App\Actions\Order\CalculateOrderEstimateAction;
use App\Actions\Product\FilterProductsAction;
use App\Actions\Product\FilterProductVariantsAction;
use App\DataTransferObjects\ProductFilterData;
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
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class ProductController extends Controller
{
    public function __construct(
        private readonly PricingRoleResolver $roleResolver,
        private readonly ProductRepositoryInterface $productRepo,
        private readonly PriceCalculationService $priceService 
    ) {}

    /**
     * لیست محصولات (Refactored)
     */
    public function index(ProductIndexRequest $request, FilterProductsAction $action): JsonResponse
    {
        $activeCode = $this->resolveActiveCode();
        $filterData = $request->toDto($activeCode);

        $paginator = $action->execute($filterData);

        return success(
            ProductResource::collection($paginator)->additional($this->paginationMeta($paginator)),
            'global.received_successfully'
        );
    }

    /**
     * نمایش جزئیات محصول (Clean)
     */
    public function show(Product $product): JsonResponse
    {
        $product->loadMissing([
            'category', 'images', 'videos', 'orderUnits', 'offers', 'aggregateLimitOrderUnit',
        ])->incrementViewCount();

        // استفاده از متد ریپازیتوری برای دریافت کارخانه‌ها
        $factories = $this->productRepo->getProductFactories($product->id);

        return success([
            'product'   => new ProductDetailResource($product),
            'factories' => FactoryResource::collection($factories),
        ], 'global.received_successfully');
    }

    /**
     * لیست واریانت‌های محصول (Refactored with Action)
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
            ProductVariantResource::collection($paginator)->additional($this->paginationMeta($paginator)),
            'global.received_successfully'
        );
    }

    /**
     * دریافت فیلترهای محصول
     */
    public function filters(Product $product): JsonResponse
    {
        $product->loadMissing('variants.variantAttributes');

        $typeIds = $product->variants
            ->flatMap(fn($v) => $v->variantAttributes->pluck(Attribute::ATTRIBUTE_TYPE_ID))
            ->unique();

        $attributes = AttributeType::whereIn(AttributeType::ID, $typeIds)
            ->with('attributes')
            ->get();

        return success(AttributeTypeResource::collection($attributes), 'global.received_successfully');
    }

    /**
     * دریافت قیمت تکی واریانت
     */
    public function getVariantPrice(ProductVariantPriceRequest $request): JsonResponse
    {
        $v = $request->validated();
        $this->handleCartSession($request); // مدیریت سشن سبد خرید

        $variant  = ProductVariant::findOrFail($v['variant_id']);
        $factory  = Factory::findOrFail($v['factory_id']);
        $location = FactoryLocation::findOrFail($v['location_id']);

        $activeCode = $this->resolveActiveCode();
        $badge      = $this->getCartBadge();
        $customerType = CustomerType::where(CustomerType::CODE, $activeCode)->first();

        $priceDto = $this->priceService->calculatePrice(
            variant: $variant,
            factory: $factory,
            location: $location,
            customerType: $customerType,
            badge: $badge,
            filterCategoryIds: null,
            withVAT: (bool)($v['with_vat'] ?? false),
            filterCategoryCode: $activeCode
        );

        return success($priceDto->toArray(), 'global.received_successfully');
    }

    /**
     * محاسبه قیمت نهایی سفارش (پیش‌فاکتور) (Refactored with Action)
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
    //  Helpers
    // =========================================================================

    private function resolveActiveCode(): string
    {
        if (!Auth::check()) return 'employer_without_consultant';
        // فرض بر این است که یوزر لود شده و ریلیشن‌ها موجودند
        return $this->roleResolver->resolve(Auth::user());
    }

    private function paginationMeta(LengthAwarePaginator $paginator): array
    {
        return [
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page'    => $paginator->lastPage(),
                'per_page'     => $paginator->perPage(),
                'total'        => $paginator->total(),
            ],
            'links' => [
                'next' => $paginator->nextPageUrl(),
                'prev' => $paginator->previousPageUrl(),
            ],
        ];
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
