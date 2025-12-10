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
use App\Models\Offer;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\ProductVariantAttribute;
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
        $activeCode = $this->resolveActiveCode();
        $withDebug  = $request->boolean('debug_prices');
        $filterData = ProductFilterData::fromRequest($request, $activeCode);

        // Fetch products based on repository type
        if ($this->productRepo instanceof ProductRepository) {
            $products = $this->productRepo->getBaseQuery($filterData)->get();
        } else {
            $products = Product::all();
        }

        // Hydrate prices for sorting purposes
        $products->each(function (Product $product) use ($activeCode, $withDebug) {
            $product->variants->each(fn($v) => $this->hydrateVariantPrices($v, $activeCode, $withDebug));

            $product->min_variant_price = $product->variants
                ->flatMap(fn($v) => $v->prices)
                ->min('final_price');
        });

        // Apply sorting & pagination
        $products = $this->applyDynamicSorting($products, $filterData->sortKey, 'products');
        $paginator = $this->manualPaginate($products, $request->input('per_page', 12));

        return success(
            ProductResource::collection($paginator)->additional($this->paginationMeta($paginator)),
            'global.received_successfully'
        );
    }

    public function show(Product $product): JsonResponse
    {
        // Load only essential details (excluding variants for performance)
        $product->loadMissing([
            'category',
            'images',
            'videos',
            'orderUnits',
            'offers',
            'aggregateLimitOrderUnit',
        ])->incrementViewCount();

        $factories = $this->productRepo->getProductFactories($product->id);

        return success([
            'product'   => new ProductDetailResource($product),
            'factories' => FactoryResource::collection($factories),
        ], 'global.received_successfully');
    }

    public function variants(ProductVariantFilterRequest $request, Product $product): JsonResponse
    {
        $v          = $request->validated();
        $activeCode = $this->resolveActiveCode();
        $withDebug  = $request->boolean('debug_prices');
        $sort       = $v['sort'] ?? 'newest';

        // 1. Build Query
        $query = $product->variants()
            ->with(['prices.factory', 'prices.factoryLocation', 'variantAttributes.attributeType', 'cartItems', 'limitOrderUnit']);

        // 2. Apply Filters
        if (!empty($v['filter_categories'])) {
            $query->whereHas(
                'variantAttributes',
                fn($q) =>
                $q->whereIn(ProductVariantAttribute::ATTRIBUTE_ID, $v['filter_categories'])
            );
        }

        // 3. Get Collection & Calculate Prices
        $variants = $query->get();
        $variants->each(fn($variant) => $this->hydrateVariantPrices($variant, $activeCode, $withDebug));

        // 4. Sort & Paginate
        $variants  = $this->applyDynamicSorting($variants, $sort, 'variants');
        $paginator = $this->manualPaginate($variants, $v['per_page'] ?? 10);

        return success(
            ProductVariantResource::collection($paginator)->additional($this->paginationMeta($paginator)),
            'global.received_successfully'
        );
    }

    public function filters(Product $product): JsonResponse
    {
        $product->loadMissing('variants.variantAttributes.attributeType');

        $typeIds = $product->variants
            ->flatMap(fn($v) => $v->variantAttributes->pluck(Attribute::ATTRIBUTE_TYPE_ID))
            ->unique();

        $attributes = AttributeType::query()
            ->whereIn(AttributeType::ID, $typeIds)
            ->with('attributes')
            ->get();

        return success(AttributeTypeResource::collection($attributes), 'global.received_successfully');
    }

    public function getVariantPrice(ProductVariantPriceRequest $request): JsonResponse
    {
        $v = $request->validated();

        $variant  = ProductVariant::findOrFail($v['variant_id']);
        $factory  = Factory::findOrFail($v['factory_id']);
        $location = FactoryLocation::findOrFail($v['location_id']);

        // Handle Cart Session
        $this->handleCartSession($request);
        $badge = $this->getCartBadge();

        $activeCode   = $this->resolveActiveCode();
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

    public function calculateOrderPrice(OrderPriceRequest $request): JsonResponse
    {
        $v = $request->validated();

        $variant  = ProductVariant::findOrFail($v['variant_id']);
        $factory  = Factory::findOrFail($v['factory_id']);
        $location = FactoryLocation::findOrFail($v['location_id']);

        $badge      = !empty($v['badge_id']) ? Badge::find($v['badge_id']) : null;
        $activeCode = $this->resolveActiveCode();
        $customerType = CustomerType::where(CustomerType::CODE, $activeCode)->first();

        $withVat = (bool)($v['with_vat'] ?? false);

        // 1. Calculate Unit Price (DTO)
        $unitDto = $this->priceService->calculatePrice(
            $variant,
            $factory,
            $location,
            $customerType,
            $badge,
            null,
            $withVat,
            $activeCode
        );

        // 2. Calculate Total (Array) using DTO property
        $order = $this->priceService->calculateTotalPrice(
            $v['quantities'],
            $unitDto->finalPrice,
            $withVat
        );

        // 3. Apply Offers
        $offers = $this->checkEligibleOffers($variant->product, $order['total_count']);
        $discounted = $this->applyDiscounts((float)$order['subtotal'], $offers);

        $vatAmount = $withVat ? $discounted * ((float)$order['vat_rate'] / 100) : 0.0;
        $final     = $discounted + $vatAmount;

        return success([
            'unit_price'         => (string)$unitDto->finalPrice,
            'unit_price_details' => $unitDto->toArray(),
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

    // =========================================================================
    //  Private Helpers
    // =========================================================================

    private function resolveActiveCode(): string
    {
        if (!Auth::check()) return 'employer_without_consultant';
        return $this->roleResolver->resolve(Auth::user()->fresh(['roles', 'employer']));
    }

    private function hydrateVariantPrices(ProductVariant $variant, string $activeCode, bool $withDebug = false): void
    {
        $variant->prices->each(function ($price) use ($variant, $activeCode, $withDebug) {
            $calcDto = $this->priceService->calculatePrice(
                $variant,
                $price->factory,
                $price->factoryLocation,
                null, // filters
                null, // badge
                null, // filterIds
                false, // withVat
                $activeCode
            );

            // Assign DTO properties to model for sorting/resources
            $price->final_price        = $calcDto->finalPrice;
            $price->role_special_price = $calcDto->payablePrice;
            $price->role_general_price = $calcDto->listPrice;
            $price->role_golden_figure = max(0.0, $calcDto->listPrice - $calcDto->payablePrice);

            if ($withDebug) {
                $price->pricing_debug = $calcDto->toArray();
            }
        });

        $variant->min_price = $variant->prices->min('final_price');
    }

    private function manualPaginate(Collection $items, int $perPage): LengthAwarePaginator
    {
        $currentPage = LengthAwarePaginator::resolveCurrentPage();
        $slice       = $items->slice(($currentPage - 1) * $perPage, $perPage)->values();

        return new LengthAwarePaginator(
            $slice,
            $items->count(),
            $perPage,
            $currentPage,
            ['path' => LengthAwarePaginator::resolveCurrentPath()]
        );
    }

    private function handleCartSession($request): void
    {
        if (Auth::check()) {
            Cart::firstOrCreate([Cart::USER_ID => Auth::id()]);
        } else {
            $sessionId = $request->cookie('cart_session_id', Str::uuid()->toString());
            $cart = Cart::firstOrCreate([Cart::SESSION_ID => $sessionId], [Cart::USER_ID => null]);
            cookie()->queue('cart_session_id', $cart->session_id, 60 * 24 * 30);
        }
    }

    private function getCartBadge(): ?Badge
    {
        $cart = Auth::check()
            ? Cart::where(Cart::USER_ID, Auth::id())->first()
            : Cart::where(Cart::SESSION_ID, request()->cookie('cart_session_id'))->first();

        return $cart && $cart->badge_id ? Badge::find($cart->badge_id) : null;
    }

    private function checkEligibleOffers(Product $product, int $totalQuantity)
    {
        return $product->offers()->current()
            ->where(Offer::MINIMUM_QUANTITY, '<=', $totalQuantity)
            ->get();
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

    private function applyDynamicSorting(Collection $collection, string $sortKey, string $type = 'products'): Collection
    {
        $getter = fn($item) => $type === 'products'
            ? ($item->min_variant_price ?? null)
            : ($item->min_price ?? null);
        $sorted = match ($sortKey) {
            'cheapest'       => $collection->sortBy($getter),
            'most_expensive' => $collection->sortByDesc($getter),
            'most_viewed'    => $collection->sortByDesc('view_count'),
            default          => $collection->sortByDesc('created_at'),
        };
        return $sorted->values();
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
}
