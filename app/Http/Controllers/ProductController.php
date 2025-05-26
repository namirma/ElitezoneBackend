<?php

namespace App\Http\Controllers;

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
use App\Models\AttributeType;
use App\Models\Badge;
use App\Models\Cart;
use App\Models\CustomerType;
use App\Models\Factory;
use App\Models\FactoryLocation;
use App\Models\PriceFilterCategory;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductPrice;
use App\Models\ProductVariant;
use App\Models\Offer;
use App\Services\PriceCalculationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;

class ProductController extends Controller
{
    public function __construct(private readonly PriceCalculationService $priceService) {}

    public function index(ProductIndexRequest $request): JsonResponse
    {
        $validatedData = $request->validated();

        $productsQuery = Product::query()->active()
            ->with(['category', 'images' => fn($imageQuery) => $imageQuery->where(ProductImage::IS_PRIMARY, true)])
            ->when($request->filled('category'), fn($query) => $query->where(Product::CATEGORY_ID, $validatedData['category']))
            ->when($request->filled('search'), fn($query) => $query->where(
                fn($subQuery) => $subQuery
                    ->where(Product::NAME, 'like', '%' . $validatedData['search'] . '%')
                    ->orWhere(Product::DESCRIPTION, 'like', '%' . $validatedData['search'] . '%')
            ));

        $this->applySorting($productsQuery, $validatedData['sort'] ?? null);

        $products = $productsQuery->paginate($request->integer('per_page', 12));

        return success(
            ProductResource::collection($products)
                ->additional($this->paginationMeta($products)),
            'global.received_successfully'
        );
    }

    public function show(Product $product): JsonResponse
    {
        $product->loadMissing([
            'category',
            'images',
            'videos',
            'variants.variantAttributes.attributeType',
            'orderUnits',
            'offers'
        ])->incrementViewCount();

        $factories = Factory::query()
            ->whereHas('prices.productVariant.product', fn($query) => $query->where(Product::ID, $product->id))
            ->with('locations')
            ->get();

        return success([
            'product'   => new ProductDetailResource($product),
            'factories' => FactoryResource::collection($factories),
        ], 'global.received_successfully');
    }

    public function filters(Product $product): JsonResponse
    {
        $typeIds = $product->variants
            ->flatMap(fn(ProductVariant $variant) => $variant->variantAttributes->pluck('attribute_type_id'))
            ->unique();

        $attributes = AttributeType::query()
            ->whereIn(AttributeType::ID, $typeIds)
            ->with('attributes')
            ->get();

        return success(AttributeTypeResource::collection($attributes), 'global.received_successfully');
    }

    public function variants(ProductVariantFilterRequest $request, Product $product): JsonResponse
    {
        $validatedData = $request->validated();
        $filterCategories = $validatedData['filter_categories'] ?? null;
        $sortOption = $validatedData['sort'] ?? null;

        $variantsQuery = $product->variants()
            ->with([
                'prices.factory',
                'prices.factoryLocation',
                'variantAttributes.attributeType',
                'cartItems'
            ])
            ->when(
                $filterCategories,
                fn($query) => $query->whereHas(
                    'variantAttributes',
                    fn($attributeQuery) => $attributeQuery->whereIn('attribute_id', $filterCategories)
                )
            );

        match ($sortOption) {
            'cheapest' => $variantsQuery
                ->withMin('prices as min_price', ProductPrice::SPECIAL_PRICE)
                ->orderBy('min_price', 'asc'),
            'most_expensive' => $variantsQuery
                ->withMax('prices as max_price', ProductPrice::SPECIAL_PRICE)
                ->orderBy('max_price', 'desc'),
            'newest' => $variantsQuery->latest(ProductVariant::CREATED_AT),
            default  => $variantsQuery->latest(ProductVariant::CREATED_AT),
        };

        $variants = $variantsQuery->paginate($request->integer('per_page', 10));

        return success(
            ProductVariantResource::collection($variants)
                ->additional($this->paginationMeta($variants)),
            'global.received_successfully'
        );
    }

    public function getVariantPrice(ProductVariantPriceRequest $request): JsonResponse
    {
        $validatedData = $request->validated();

        $variant = ProductVariant::find($validatedData['variant_id']);
        if (!$variant) return error('global.variant_not_found', [], 404);

        $factory = Factory::find($validatedData['factory_id']);
        if (!$factory) return error('global.factory_not_found', [], 404);

        $location = FactoryLocation::find($validatedData['location_id']);
        if (!$location) return error('global.location_not_found', [], 404);


        $sessionId = $request->cookie('cart_session_id', \Illuminate\Support\Str::uuid()->toString());
        $cart = Auth::check()
            ? Cart::firstOrCreate([Cart::USER_ID => Auth::id()])
            : Cart::firstOrCreate([Cart::SESSION_ID => $sessionId], [Cart::USER_ID => null]);

        if (!Auth::check()) {
            cookie()->queue('cart_session_id', $cart->session_id, 60 * 24 * 30);
        }

        $customerTypeId = $validatedData['customer_type_id'] ?? $cart->{Cart::CUSTOMER_TYPE_ID};
        $customerType = CustomerType::find($customerTypeId);
        $badge = $cart->{Cart::BADGE_ID} ? Badge::find($cart->{Cart::BADGE_ID}) : null;

        $filterCatIds = $cart->priceFilterCategories()->pluck(PriceFilterCategory::ID)->toArray();
        if (empty($filterCatIds)) {
            $fallback = PriceFilterCategory::where(PriceFilterCategory::CODE, 'employer_without_consultant')->value(PriceFilterCategory::ID);
            $filterCatIds = $fallback ? [$fallback] : [];
        }

        $price = $this->priceService->calculatePrice(
            $variant,
            $factory,
            $location,
            $customerType,
            $badge,
            $filterCatIds,
            (bool)($validatedData['with_vat'] ?? false)
        );

        return success($price, 'global.received_successfully');
    }

    public function calculateOrderPrice(OrderPriceRequest $request): JsonResponse
    {
        $validatedData = $request->validated();

        $variant = ProductVariant::find($validatedData['variant_id']);
        if (!$variant) return error('global.variant_not_found', [], 404);

        $factory = Factory::find($validatedData['factory_id']);
        if (!$factory) return error('global.factory_not_found', [], 404);

        $location = FactoryLocation::find($validatedData['location_id']);
        if (!$location) return error('global.location_not_found', [], 404);

        $badge = $validatedData['badge_id'] ?? null ? Badge::find($validatedData['badge_id']) : null;

        $paramIds = $validatedData['parameters'] ?? [];
        if (empty($paramIds)) {

            $sessionIdForCart = $request->cookie('cart_session_id', \Illuminate\Support\Str::uuid()->toString());
            $cart = Auth::check()
                ? Cart::firstOrCreate([Cart::USER_ID => Auth::id()])
                : Cart::firstOrCreate([Cart::SESSION_ID => $sessionIdForCart], [Cart::USER_ID => null]);
            if(!Auth::check()) cookie()->queue('cart_session_id', $cart->session_id, 60 * 24 * 30); 
            $paramIds = $cart->purchaseParameters()->pluck('purchase_parameter_values.id')->toArray();
        }

        $priceFilterCat = app(ParameterDetectionController::class)
            ->detectPriceFilterCategoryFromValues($paramIds)
            ?? PriceFilterCategory::where(PriceFilterCategory::CODE, 'employer_without_consultant')->firstOrFail();

        $customerType = CustomerType::where(CustomerType::CODE, $priceFilterCat->code)->firstOrFail();

        $withVat = (bool)($validatedData['with_vat'] ?? false);
        $unitPriceDetails  = $this->priceService->calculatePrice($variant, $factory, $location, $customerType, $badge, [$priceFilterCat->id], $withVat);
        $orderDetails = $this->priceService->calculateTotalPrice($validatedData['quantities'], $unitPriceDetails['final_price'], $withVat);

        $offers     = $this->checkEligibleOffers($variant->product, $orderDetails['total_count']);
        $discountedPrice = $this->applyDiscounts($orderDetails['subtotal'], $offers);
        $vatAmount  = $withVat ? $discountedPrice * ($orderDetails['vat_rate'] / 100) : 0;
        $finalPrice = $discountedPrice + $vatAmount;

        return success([
            'unit_price'         => (string)$unitPriceDetails['final_price'],
            'unit_price_details' => $unitPriceDetails,
            'order_details'      => $orderDetails,
            'offers'             => OfferResource::collection($offers),
            'discount_amount'    => (string)($orderDetails['subtotal'] - $discountedPrice),
            'discounted_price'   => (string)$discountedPrice,
            'vat_amount'         => (string)$vatAmount,
            'vat_rate'           => (string)($withVat ? $orderDetails['vat_rate'] : 0),
            'final_price'        => (string)$finalPrice,
            'with_vat'           => $withVat,
        ], 'global.received_successfully');
    }

    private function applySorting($query, ?string $sort): void
    {
        match ($sort) {
            'cheapest' => $query
                ->withMin('variantPrices', ProductPrice::SPECIAL_PRICE)
                ->orderBy('variant_prices_min_special_price', 'asc'),
            'most_expensive' => $query
                ->withMax('variantPrices', ProductPrice::SPECIAL_PRICE)
                ->orderBy('variant_prices_max_special_price', 'desc'),
            'most_viewed'  => $query->orderBy(Product::VIEW_COUNT, 'desc'),
            'best_selling' => $query->withCount('orderItems as sold_count')
                ->orderBy('sold_count', 'desc'),
            'newest'       => $query->orderBy(Product::CREATED_AT, 'desc'),
            default        => $query->latest(Product::CREATED_AT),
        };
    }

    private function checkEligibleOffers(Product $product, int $totalQuantity)
    {
        return $product->offers()
            ->current()
            ->where(Offer::MINIMUM_QUANTITY, '<=', $totalQuantity)
            ->get();
    }

    private function applyDiscounts(float $price, $offers): float
    {
        foreach ($offers as $offer) {
            $price = match ($offer->{Offer::TYPE}) {
                'percentage'   => $price - ($price * $offer->{Offer::VALUE} / 100),
                'fixed_amount' => $price - $offer->{Offer::VALUE},
                default        => $price,
            };
        }
        return max(0, $price);
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
