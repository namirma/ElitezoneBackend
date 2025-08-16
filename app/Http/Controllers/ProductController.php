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
use App\Models\ProductPrice;
use App\Models\ProductVariant;
use App\Models\ProductVariantAttribute;
use App\Models\PurchaseParameterValue;
use App\Models\Offer;
use App\Services\PriceCalculationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ProductController extends Controller
{
    public function __construct(private readonly PriceCalculationService $priceService) {}

    public function index(ProductIndexRequest $request): JsonResponse
    {
        $validatedData = $request->validated();
        $productsQuery = Product::query()->active()
            ->with(['category', 'images' => fn($imageQuery) => $imageQuery->where(ProductImage::IS_PRIMARY, true)])
            ->when(!empty($validatedData['category']), fn($query) => $query->where(Product::CATEGORY_ID, $validatedData['category']))
            ->when(!empty($validatedData['search']), fn($query) => $query->where(
                fn($subQuery) => $subQuery
                    ->where(Product::NAME, 'like', '%' . $validatedData['search'] . '%')
                    ->orWhere(Product::DESCRIPTION, 'like', '%' . $validatedData['search'] . '%')
            ));
        $sortKey = $validatedData['sort'] ?? 'newest';
        $this->applySorting($productsQuery, $sortKey);
        $perPage = !empty($validatedData['per_page']) ? (int)$validatedData['per_page'] : 12;
        $products = $productsQuery->paginate($perPage);
        return success(
            ProductResource::collection($products)->additional($this->paginationMeta($products)),
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
            'variants.limitOrderUnit',
            'variants.product.orderUnits',
            'orderUnits',
            'offers',
            'aggregateLimitOrderUnit'
        ])->incrementViewCount();
        $factories = Factory::query()
            ->whereHas('prices.productVariant.product', fn($query) => $query->where(Product::ID, $product->id))
            ->with('locations')->get();
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
            ->whereIn(AttributeType::ID, $typeIds)->with('attributes')->get();
        return success(AttributeTypeResource::collection($attributes), 'global.received_successfully');
    }

    public function variants(ProductVariantFilterRequest $request, Product $product): JsonResponse
    {
        $validatedData = $request->validated();
        $filterCategories = $validatedData['filter_categories'] ?? null;
        $sortOption = $validatedData['sort'] ?? 'newest';
        $variantsQuery = $product->variants()
            ->with(['prices.factory', 'prices.factoryLocation', 'variantAttributes.attributeType', 'cartItems', 'limitOrderUnit'])
            ->when($filterCategories, fn($query) => $query->whereHas(
                'variantAttributes',
                fn($attributeQuery) => $attributeQuery->whereIn(ProductVariantAttribute::ATTRIBUTE_ID, $filterCategories)
            ));
        match ($sortOption) {
            'cheapest' => $variantsQuery->withMin('prices as min_price', ProductPrice::SPECIAL_PRICE)->orderBy('min_price', 'asc'),
            'most_expensive' => $variantsQuery->withMax('prices as max_price', ProductPrice::SPECIAL_PRICE)->orderBy('max_price', 'desc'),
            'newest' => $variantsQuery->latest(ProductVariant::CREATED_AT),
            'most_viewed' => $variantsQuery->orderBy(ProductVariant::VIEW_COUNT, 'desc'),
            default  => $variantsQuery->latest(ProductVariant::CREATED_AT),
        };
        $perPage = !empty($validatedData['per_page']) ? (int)$validatedData['per_page'] : 10;
        $variants = $variantsQuery->paginate($perPage);
        return success(
            ProductVariantResource::collection($variants)->additional($this->paginationMeta($variants)),
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

        $sessionId = $request->cookie('cart_session_id', Str::uuid()->toString());
        $cart = Auth::check()
            ? Cart::firstOrCreate([Cart::USER_ID => Auth::id()])
            : Cart::firstOrCreate([Cart::SESSION_ID => $sessionId], [Cart::USER_ID => null]);
        if (!Auth::check()) {
            cookie()->queue('cart_session_id', $cart->session_id, 60 * 24 * 30);
        }
        $activeCode   = app('active_price_filter_category_code');
        $pf           = PriceFilterCategory::where('code', $activeCode)->first();
        $filterCatIds = $pf ? [$pf->id] : [];

        $customerType = CustomerType::where('code', $activeCode)->first();
        $badge        = $cart->badge_id ? Badge::find($cart->badge_id) : null;
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
        $v = $request->validated();

        $variant = ProductVariant::find($v['variant_id']);
        if (!$variant) return error('global.variant_not_found', [], 404);

        $factory = Factory::find($v['factory_id']);
        if (!$factory) return error('global.factory_not_found', [], 404);

        $location = FactoryLocation::find($v['location_id']);
        if (!$location) return error('global.location_not_found', [], 404);

        $badge = !empty($v['badge_id']) ? Badge::find($v['badge_id']) : null;
        $activeCode = app('active_price_filter_category_code') ?? 'employer_without_consultant';

        $priceFilterCat = PriceFilterCategory::where(PriceFilterCategory::CODE, $activeCode)->first()
            ?? PriceFilterCategory::where(PriceFilterCategory::CODE, 'employer_without_consultant')->firstOrFail();
        $customerType = CustomerType::where(CustomerType::CODE, $activeCode)->first()
            ?? CustomerType::where(CustomerType::CODE, 'employer_without_consultant')->firstOrFail();

        $withVat = (bool)($v['with_vat'] ?? false);
        $unitPriceDetails = $this->priceService->calculatePrice(
            $variant,
            $factory,
            $location,
            $customerType,
            $badge,
            [$priceFilterCat->id],
            $withVat
        );
        $orderDetails = $this->priceService->calculateTotalPrice(
            $v['quantities'],
            (float)$unitPriceDetails['final_price'],
            $withVat
        );
        $offers = $this->checkEligibleOffers($variant->product, $orderDetails['total_count']);
        $discountedPrice = $this->applyDiscounts((float)$orderDetails['subtotal'], $offers);

        // مالیات
        $vatAmount  = $withVat ? $discountedPrice * ((float)$orderDetails['vat_rate'] / 100) : 0.0;
        $finalPrice = $discountedPrice + $vatAmount;

        return success([
            'unit_price'         => (string)$unitPriceDetails['final_price'],
            'unit_price_details' => $unitPriceDetails,
            'order_details'      => $orderDetails,
            'offers'             => OfferResource::collection($offers),
            'discount_amount'    => (string)($orderDetails['subtotal'] - $discountedPrice),
            'discounted_price'   => (string)$discountedPrice,
            'vat_amount'         => (string)$vatAmount,
            'vat_rate'           => (string)($withVat ? $orderDetails['vat_rate'] : 0.0),
            'final_price'        => (string)$finalPrice,
            'with_vat'           => $withVat,
        ], 'global.received_successfully');
    }

    private function applySorting($query, ?string $sort): void
    {
        match ($sort) {
            'cheapest' => $query->withMin('variants.prices as min_variant_price', ProductPrice::SPECIAL_PRICE)->orderBy('min_variant_price', 'asc'),
            'most_expensive' => $query->withMax('variants.prices as max_variant_price', ProductPrice::SPECIAL_PRICE)->orderBy('max_variant_price', 'desc'),
            'most_viewed'  => $query->withSum('variants as variants_total_view_count', ProductVariant::VIEW_COUNT)->orderBy(DB::raw('COALESCE(variants_total_view_count, 0)'), 'desc'),
            'best_selling' => $query->withCount('orderItems as sold_count')->orderBy('sold_count', 'desc'),
            'newest'       => $query->orderBy(Product::CREATED_AT, 'desc'),
            default        => $query->latest(Product::CREATED_AT),
        };
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
            'meta' => ['current_page' => $paginator->currentPage(), 'last_page' => $paginator->lastPage(), 'per_page' => $paginator->perPage(), 'total' => $paginator->total()],
            'links' => ['next' => $paginator->nextPageUrl(), 'prev' => $paginator->previousPageUrl()],
        ];
    }
}
