<?php

namespace App\Http\Controllers;

use App\DataTransferObjects\ProductFilterData;
use App\Http\Controllers\Controller;
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
use App\Models\PriceFilterCategory;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use App\Models\ProductVariantAttribute;
use App\Models\Offer;
use App\Repositories\Contracts\ProductRepositoryInterface;
use App\Repositories\Eloquent\ProductRepository;
use App\Services\PriceCalculationService;
use App\Services\PricingRoleResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class ProductController extends Controller
{
    public function __construct(
        private readonly PriceCalculationService $priceService,
        private readonly PricingRoleResolver     $roleResolver,
        private readonly ProductRepositoryInterface $productRepo
    ) {}

    public function index(ProductIndexRequest $request): JsonResponse
    {
        // 1. دریافت نقش کاربر
        $activeCode = $this->resolveActiveCode();
        $withDebug  = $request->boolean('debug_prices');

        $filterData = ProductFilterData::fromRequest($request, $activeCode);

        if ($this->productRepo instanceof ProductRepository) {
            $productsQuery = $this->productRepo->getBaseQuery($filterData);
            $products = $productsQuery->get();
        } else {
            $products = Product::all();
        }

        $products->each(function (Product $product) use ($activeCode, $withDebug) {
            $product->variants->each(function ($variant) use ($activeCode, $withDebug) {
                $this->hydrateVariantPrices($variant, $activeCode, $withDebug);
            });

            $product->min_variant_price = $product->variants
                ->flatMap(fn($v) => $v->prices)
                ->min('final_price');
        });

        $sortKey = $filterData->sortKey;
        $products = $this->applyDynamicSorting($products, $sortKey, 'products');

        // 6. صفحه‌بندی دستی
        $perPage      = $request->input('per_page', 12);
        $currentPage  = LengthAwarePaginator::resolveCurrentPage();
        $slice        = $products->slice(($currentPage - 1) * $perPage, $perPage)->values();

        $paginator = new LengthAwarePaginator(
            $slice,
            $products->count(),
            $perPage,
            $currentPage,
            ['path' => LengthAwarePaginator::resolveCurrentPath()]
        );

        return success(
            ProductResource::collection($paginator)->additional($this->paginationMeta($paginator)),
            'global.received_successfully'
        );
    }

    public function show(Product $product): JsonResponse
    {
        $activeCode = $this->resolveActiveCode();
        $withDebug  = request()->boolean('debug_prices');

        $product->loadMissing([
            'category',
            'images',
            'videos',
            'variants.variantAttributes.attributeType',
            'variants.limitOrderUnit',
            'variants.product.orderUnits',
            'variants.prices.factory',
            'variants.prices.factoryLocation',
            'orderUnits',
            'offers',
            'aggregateLimitOrderUnit',
        ])->incrementViewCount();

        // محاسبه قیمت
        $product->variants->each(function (ProductVariant $variant) use ($activeCode, $withDebug) {
            $this->hydrateVariantPrices($variant, $activeCode, $withDebug);
        });

        $factories = $this->productRepo->getProductFactories($product->id);

        return success([
            'product'   => new ProductDetailResource($product),
            'factories' => FactoryResource::collection($factories),
        ], 'global.received_successfully');
    }

    public function filters(Product $product): JsonResponse
    {
        $product->loadMissing('variants.variantAttributes.attributeType');
        $typeIds = $product->variants
            ->flatMap(fn(ProductVariant $variant) => $variant->variantAttributes->pluck(Attribute::ATTRIBUTE_TYPE_ID))
            ->unique();

        $attributes = AttributeType::query()
            ->whereIn(AttributeType::ID, $typeIds)
            ->with('attributes')
            ->get();

        return success(AttributeTypeResource::collection($attributes), 'global.received_successfully');
    }

    public function variants(ProductVariantFilterRequest $request, Product $product): JsonResponse
    {
        $v          = $request->validated();
        $activeCode = $this->resolveActiveCode();
        $withDebug  = $request->boolean('debug_prices');
        $sort       = $v['sort'] ?? 'newest';

        $variants = $product->variants()
            ->with(['prices.factory', 'prices.factoryLocation', 'variantAttributes.attributeType', 'cartItems', 'limitOrderUnit'])
            ->when(!empty($v['filter_categories']), function ($q) use ($v) {
                $q->whereHas('variantAttributes', function ($qq) use ($v) {
                    $qq->whereIn(ProductVariantAttribute::ATTRIBUTE_ID, $v['filter_categories']);
                });
            })
            ->get();

        $variants->each(function (ProductVariant $variant) use ($activeCode, $withDebug) {
            $this->hydrateVariantPrices($variant, $activeCode, $withDebug);
        });

        $variants = $this->applyDynamicSorting($variants, $sort, 'variants');

        $perPage      = !empty($v['per_page']) ? (int) $v['per_page'] : 10;
        $currentPage  = LengthAwarePaginator::resolveCurrentPage();
        $slice        = $variants->slice(($currentPage - 1) * $perPage, $perPage)->values();
        $paginator    = new LengthAwarePaginator($slice, $variants->count(), $perPage, $currentPage);

        return success(
            ProductVariantResource::collection($paginator)->additional($this->paginationMeta($paginator)),
            'global.received_successfully'
        );
    }

    public function getVariantPrice(ProductVariantPriceRequest $request): JsonResponse
    {
        $v          = $request->validated();
        $variant    = ProductVariant::find($v['variant_id']);
        if (!$variant) return error('global.variant_not_found', [], 404);
        $factory    = Factory::find($v['factory_id']);
        if (!$factory) return error('global.factory_not_found', [], 404);
        $location   = FactoryLocation::find($v['location_id']);
        if (!$location) return error('global.location_not_found', [], 404);

        // سشن سبد برای نشان‌ها
        $sessionId = $request->cookie('cart_session_id', Str::uuid()->toString());
        $cart = Auth::check()
            ? Cart::firstOrCreate([Cart::USER_ID => Auth::id()])
            : Cart::firstOrCreate([Cart::SESSION_ID => $sessionId], [Cart::USER_ID => null]);
        if (!Auth::check()) {
            cookie()->queue('cart_session_id', $cart->session_id, 60 * 24 * 30);
        }

        $activeCode = $this->resolveActiveCode();
        $customerType = CustomerType::where(CustomerType::CODE, $activeCode)->first();
        $badge        = $cart->badge_id ? Badge::find($cart->badge_id) : null;

        $price = $this->priceService->calculatePrice(
            $variant,
            $factory,
            $location,
            $customerType,
            $badge,
            null,
            (bool)($v['with_vat'] ?? false),
            $activeCode
        );

        return success($price, 'global.received_successfully');
    }

    public function calculateOrderPrice(OrderPriceRequest $request): JsonResponse
    {
        $v          = $request->validated();
        $variant    = ProductVariant::find($v['variant_id']);
        if (!$variant) return error('global.variant_not_found', [], 404);
        $factory    = Factory::find($v['factory_id']);
        if (!$factory) return error('global.factory_not_found', [], 404);
        $location   = FactoryLocation::find($v['location_id']);
        if (!$location) return error('global.location_not_found', [], 404);

        $badge      = !empty($v['badge_id']) ? Badge::find($v['badge_id']) : null;
        $activeCode = $this->resolveActiveCode();
        $customerType = CustomerType::where(CustomerType::CODE, $activeCode)->first();

        $withVat = (bool)($v['with_vat'] ?? false);

        $unit = $this->priceService->calculatePrice(
            $variant,
            $factory,
            $location,
            $customerType,
            $badge,
            null,
            $withVat,
            $activeCode
        );

        $order = $this->priceService->calculateTotalPrice(
            $v['quantities'],
            (float)$unit['final_price'],
            $withVat
        );

        $offers = $this->checkEligibleOffers($variant->product, $order['total_count']);
        $discounted = $this->applyDiscounts((float)$order['subtotal'], $offers);

        $vatAmount = $withVat ? $discounted * ((float)$order['vat_rate'] / 100) : 0.0;
        $final     = $discounted + $vatAmount;

        return success([
            'unit_price'         => (string)$unit['final_price'],
            'unit_price_details' => $unit,
            'order_details'      => $order,
            'offers'             => OfferResource::collection($offers),
            'discount_amount'    => (string)($order['subtotal'] - $discounted),
            'discounted_price'   => (string)$discounted,
            'vat_amount'         => (string)$vatAmount,
            'vat_rate'           => (string)($withVat ? $order['vat_rate'] : 0.0),
            'final_price'        => (string)$final,
            'with_vat'           => $withVat,
        ], 'global.received_successfully');
    }

    /* -------------------- Helpers -------------------- */

    private function resolveActiveCode(): string
    {
        if (!Auth::check()) {
            return 'employer_without_consultant';
        }
        $user = Auth::user()->fresh(['roles', 'employer']);
        return $this->roleResolver->resolve($user);
    }
    private function hydrateVariantPrices(ProductVariant $variant, string $activeCode, bool $withDebug = false): void
    {
        $variant->prices->each(function ($price) use ($variant, $activeCode, $withDebug) {
            $calc = $this->priceService->calculatePrice(
                $variant,
                $price->factory,
                $price->factoryLocation,
                null,
                null,
                null,
                false,
                $activeCode
            );

            $price->final_price        = (float)$calc['final_price'];
            $price->role_special_price = (float)$calc['special_price_with_filters'];
            $price->role_general_price = (float)$calc['general_price_with_filters'];
            $price->role_golden_figure = (float)$calc['role_golden_figure'];

            if ($withDebug) {
                $price->pricing_debug = $calc;
            }
        });

        $variant->min_price = $variant->prices->min('final_price');
    }

    private function checkEligibleOffers(Product $product, int $totalQuantity)
    {
        return $product->offers()->current()->where(Offer::MINIMUM_QUANTITY, '<=', $totalQuantity)->get();
    }

    private function applyDiscounts(float $price, Collection $offers): float
    {
        foreach ($offers as $offer) {
            $price = match ($offer->{Offer::TYPE}) {
                'percentage'   => $price - ($price * (float)$offer->{Offer::VALUE} / 100),
                'fixed_amount' => $price - (float)$offer->{Offer::VALUE},
                default        => $price,
            };
        }
        return max(0.0, $price);
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

    private function applyDynamicSorting(Collection $collection, string $sortKey, string $type = 'products'): Collection
    {
        $minGetter = function ($item) use ($type) {
            return $type === 'products'
                ? ($item->min_variant_price ?? null)
                : ($item->min_price ?? null);
        };

        switch ($sortKey) {
            case 'cheapest':
                $collection = $collection->sortBy(fn($item) => $minGetter($item));
                break;
            case 'most_expensive':
                $collection = $collection->sortByDesc(fn($item) => $minGetter($item));
                break;
            case 'most_viewed':
                $collection = $collection->sortByDesc('view_count');
                break;
            case 'newest':
            default:
                $collection = $collection->sortByDesc('created_at');
                break;
        }

        return $collection->values();
    }
}
