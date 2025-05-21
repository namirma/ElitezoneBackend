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
use App\Models\ProductVariant;
use App\Services\PriceCalculationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Pagination\LengthAwarePaginator;
use Dotenv\Exception\ValidationException;

class ProductController extends Controller
{
    public function __construct(private readonly PriceCalculationService $priceService) {}

    public function index(ProductIndexRequest $request): JsonResponse
    {
        $query = Product::active()
            ->with(['category', 'images' => fn($q) => $q->where('is_primary', true)])
            ->when($request->filled('category'), fn($q) => $q->where(Product::CATEGORY_ID, $request->validated('category')))
            ->when($request->filled('search'), fn($q) => $q->where(
                fn($q2) => $q2
                    ->where(Product::NAME, 'like', '%' . $request->validated('search') . '%')
                    ->orWhere(Product::DESCRIPTION, 'like', '%' . $request->validated('search') . '%')
            ));

        $this->applySorting($query, $request->validated('sort') ?? null);

        $products = $query->paginate($request->integer('per_page', 12));

        return success(
            ProductResource::collection($products)
                ->additional($this->paginationMeta($products))
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

        $factories = Factory::whereHas('prices.productVariant.product', fn($q) => $q->where(Product::ID, $product->id))
            ->with('locations')
            ->get();

        return success([
            'product'   => new ProductDetailResource($product),
            'factories' => FactoryResource::collection($factories),
        ]);
    }

    public function filters(Product $product): JsonResponse
    {
        $typeIds = $product->variants
            ->flatMap(fn($v) => $v->variantAttributes->pluck('attribute_type_id'))
            ->unique();

        $attributes = AttributeType::whereIn(AttributeType::ID, $typeIds)
            ->with('attributes')
            ->get();

        return success(AttributeTypeResource::collection($attributes));
    }

    public function variants(ProductVariantFilterRequest $request, Product $product): JsonResponse
    {
        $variants = $product->variants()
            ->with([
                'prices.factory',
                'prices.factoryLocation',
                'variantAttributes.attributeType',
                'cartItems'
            ])

            ->when(
                $request->filled('filter_categories'),
                fn($q) => $q->whereHas(
                    'variantAttributes',
                    fn($qq) => $qq->whereIn('attribute_id', $request->validated('filter_categories'))
                )
            )
            ->tap(function ($q) use ($request) {
                match ($request->input('sort')) {
                    'cheapest' => $q
                        ->withMin('prices as min_price', 'special_price')
                        ->orderBy('min_price', 'asc'),

                    'most_expensive' => $q
                        ->withMax('prices as max_price', 'special_price')
                        ->orderBy('max_price', 'desc'),

                    'newest' => $q->latest(),
                    default  => null,
                };
            })

            ->paginate($request->integer('per_page', 10));

        return success(
            ProductVariantResource::collection($variants)
                ->additional($this->paginationMeta($variants))
        );
    }

    public function getVariantPrice(ProductVariantPriceRequest $request): JsonResponse
    {
        try {
            $v = $request->validated();
        } catch (ValidationException $e) {
            throw $e;
        }

        $variant  = ProductVariant::find($v['variant_id'])    ?: abort(404, __('global.variant_not_found'));
        $factory  = Factory::find($v['factory_id'])          ?: abort(404, __('global.factory_not_found'));
        $location = FactoryLocation::find($v['location_id']) ?: abort(404, __('global.location_not_found'));

        $cart = auth()->check()
            ? Cart::firstOrCreate(['user_id' => auth()->id()])
            : Cart::firstOrCreate(['session_id' => $request->cookie('cart_session_id', session()->getId())], ['user_id' => null]);

        if (! auth()->check()) {
            cookie()->queue('cart_session_id', $cart->session_id, 60 * 24 * 30);
        }

        $customerType = $v['customer_type_id'] ?? null
            ? CustomerType::find($v['customer_type_id'])
            : CustomerType::find($cart->customer_type_id);

        $badge = $cart->badge_id ? Badge::find($cart->badge_id) : null;

        $filterCatIds = $cart->priceFilterCategories()->pluck('id')->toArray();
        if (empty($filterCatIds)) {
            $fallback = PriceFilterCategory::where('code', 'employer_without_consultant')->value('id');
            $filterCatIds = $fallback ? [$fallback] : [];
        }

        $price = $this->priceService->calculatePrice(
            $variant,
            $factory,
            $location,
            $customerType,
            $badge,
            $filterCatIds,
            (bool)($v['with_vat'] ?? false)
        );

        return success($price);
    }

    public function calculateOrderPrice(OrderPriceRequest $request): JsonResponse
    {
        $v        = $request->validated();
        $variant  = ProductVariant::findOrFail($v['variant_id']);
        $factory  = Factory::findOrFail($v['factory_id']);
        $location = FactoryLocation::findOrFail($v['location_id']);
        $badge    = isset($v['badge_id']) ? Badge::find($v['badge_id']) : null;

        $paramIds = $v['parameters'] ?? [];
        if (empty($paramIds)) {
            $paramIds = auth()->check()
                ? auth()->user()->purchaseParameters()->pluck('purchase_parameter_values.id')->toArray()
                : Cart::firstOrCreate(['session_id' => request()->cookie('cart_session_id', session()->getId())], ['user_id' => null])
                ->purchaseParameters()->pluck('purchase_parameter_values.id')->toArray();
        }

        $priceFilterCat = app(ParameterDetectionController::class)
            ->detectPriceFilterCategoryFromValues($paramIds)
            ?? PriceFilterCategory::where('code', 'employer_without_consultant')->first();

        $customerType = CustomerType::where('code', $priceFilterCat->code)->first();

        $unit  = $this->priceService->calculatePrice($variant, $factory, $location, $customerType, $badge, [$priceFilterCat->id], (bool)$v['with_vat']);
        $order = $this->priceService->calculateTotalPrice($v['quantities'], $unit['final_price'], (bool)$v['with_vat']);

        $offers     = $this->checkEligibleOffers($variant->product, $order['total_count']);
        $discounted = $this->applyDiscounts($order['subtotal'], $offers);
        $vatAmount  = $v['with_vat'] ? $discounted * ($order['vat_rate'] / 100) : 0;

        return success([
            'unit_price'         => (string)$unit['final_price'],
            'unit_price_details' => $unit,
            'order_details'      => $order,
            'offers'             => OfferResource::collection($offers),
            'discount_amount'    => (string)($order['subtotal'] - $discounted),
            'discounted_price'   => (string)$discounted,
            'vat_amount'         => (string)$vatAmount,
            'vat_rate'           => (string)($v['with_vat'] ? $order['vat_rate'] : 0),
            'final_price'        => (string)($discounted + $vatAmount),
            'with_vat'           => (bool)$v['with_vat'],
        ]);
    }

    private function applySorting($query, ?string $sort): void
    {
        match ($sort) {
            'cheapest' => $query
                ->withMin('variantPrices', 'special_price')
                ->orderBy('variant_prices_min_special_price', 'asc'),

            'most_expensive' => $query
                ->withMax('variantPrices', 'special_price')
                ->orderBy('variant_prices_max_special_price', 'desc'),

            'most_viewed'  => $query->orderBy(Product::VIEW_COUNT, 'desc'),
            'best_selling' => $query->withCount('orderItems as sold_count')
                ->orderBy('sold_count', 'desc'),
            'newest'       => $query->orderBy(Product::CREATED_AT, 'desc'),
            default        => $query->latest(),
        };
    }

    private function checkEligibleOffers($product, int $totalQuantity)
    {
        return $product->offers()
            ->active()
            ->where('start_date', '<=', now())
            ->where(fn($q) => $q->where('end_date', '>=', now())->orWhereNull('end_date'))
            ->where('minimum_quantity', '<=', $totalQuantity)
            ->get();
    }

    private function applyDiscounts(float $price, $offers): float
    {
        foreach ($offers as $offer) {
            $price = match ($offer->type) {
                'percentage'   => $price - ($price * $offer->value / 100),
                'fixed_amount' => $price - $offer->value,
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
