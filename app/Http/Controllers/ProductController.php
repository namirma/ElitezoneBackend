<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Requests\ProductIndexRequest;
use App\Http\Requests\ProductVariantPriceRequest;
use App\Http\Requests\OrderPriceRequest;
use App\Http\Requests\ProductVariantFilterRequest;
use App\Http\Resources\AttributeTypeResource;
use App\Http\Resources\ProductResource;
use App\Http\Resources\FactoryResource;
use App\Http\Resources\PriceFilterCategoryResource;
use App\Http\Resources\ProductDetailResource;
use App\Http\Resources\ProductVariantResource;
use App\Models\AttributeType;
use App\Models\Product;
use App\Models\Factory;
use App\Models\FactoryLocation;
use App\Models\ProductVariant;
use App\Models\CustomerType;
use App\Models\Badge;
use App\Models\PriceFilterCategory;
use App\Services\PriceCalculationService;
use Illuminate\Http\JsonResponse;

class ProductController extends Controller
{
    protected PriceCalculationService $priceService;

    public function __construct(PriceCalculationService $priceService)
    {
        $this->priceService = $priceService;
    }

    /**
     * لیست محصولات
     */
    public function index(ProductIndexRequest $request)
    {
        $query = Product::with(['category', 'images' => function ($q) {
            $q->where('is_primary', true);
        }])->active();

        if ($request->filled('category')) {
            $query->where(Product::CATEGORY_ID, $request->category);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where(Product::NAME, 'like', "%{$search}%")
                    ->orWhere(Product::DESCRIPTION, 'like', "%{$search}%");
            });
        }

        if ($request->filled('sort')) {
            $this->applySorting($query, $request->sort);
        } else {
            $query->latest();
        }

        $perPage = $request->input('per_page', 12);

        return ProductResource::collection($query->paginate($perPage));
    }


    public function show($slug)
    {
        $product = Product::with([
            'category',
            'images',
            'videos',
            'variants.variantAttributes.attributeType',
            'orderUnits',
            'offers',
        ])->where(Product::SLUG, $slug)
            ->firstOrFail();

        $product->incrementViewCount();

        $factories = Factory::whereHas('prices.productVariant.product', fn($q) => $q->where(Product::ID, $product->id))
            ->with('locations')
            ->get();

        $priceFilterCategories = PriceFilterCategory::active()
            ->with(['filters' => fn($q) => $q->active()->with('filterType')])
            ->orderBy('sort_order')
            ->get();

        return response()->json([
            'status' => 'success',
            'data'   => [
                'product'                 => new ProductDetailResource($product),
                'factories'               => FactoryResource::collection($factories),
                'price_filter_categories' => PriceFilterCategoryResource::collection($priceFilterCategories),
            ]
        ]);
    }

    public function filters(string $slug)
    {

        $product = Product::where(Product::SLUG, $slug)->firstOrFail();

        $typeIds = $product
            ->variants
            ->flatMap(fn($v) => $v->variantAttributes->pluck('attribute_type_id'))
            ->unique()
            ->all();

        // 3. واکشی AttributeType‌ها به همراه Attributes
        $attributeTypes = AttributeType::whereIn(AttributeType::ID, $typeIds)
            ->with(['attributes'])
            ->get();

        // 4. برگرداندن Resource
        return response()->json([
            'status' => 'success',
            'data'   => AttributeTypeResource::collection($attributeTypes),
        ]);
    }


    public function variants(ProductVariantFilterRequest $request, string $slug): JsonResponse
    {
        $product = Product::where(Product::SLUG, $slug)->firstOrFail();

        $query = $product->variants()
            ->with(['prices', 'variantAttributes.attributeType', 'cartItems']);

        // فقط همین بخش فیلتر تغییر کرده
        if ($request->filled('filter_categories')) {
            $ids = $request->input('filter_categories');
            $query->whereHas(
                'variantAttributes',
                fn($q) =>
                $q->whereIn('attribute_id', $ids)
            );
        }

        if ($request->filled('sort')) {}

        $variants = $query->paginate($request->input('per_page', 10));

        return response()->json([
            'status' => 'success',
            'data'   => ProductVariantResource::collection($variants)
                ->additional([
                    'meta' => [
                        'current_page' => $variants->currentPage(),
                        'last_page'    => $variants->lastPage(),
                        'per_page'     => $variants->perPage(),
                        'total'        => $variants->total(),
                    ],
                    'links' => [
                        'next' => $variants->nextPageUrl(),
                        'prev' => $variants->previousPageUrl(),
                    ]
                ]),
        ]);
    }

    public function getVariantPrice(ProductVariantPriceRequest $request)
    {
        $variant = ProductVariant::findOrFail($request->variant_id);
        $factory = Factory::findOrFail($request->factory_id);
        $location = FactoryLocation::findOrFail($request->location_id);
        $customerType = CustomerType::findOrFail($request->customer_type_id);
        $badge = $request->badge_id ? Badge::findOrFail($request->badge_id) : null;

        $priceDetails = $this->priceService->calculatePrice(
            $variant,
            $factory,
            $location,
            $customerType,
            $badge,
            $request->filter_categories,
            $request->boolean('with_vat', false)
        );

        return response()->json([
            'status' => 'success',
            'data' => $priceDetails
        ]);
    }


    public function calculateOrderPrice(OrderPriceRequest $request)
    {
        $variant      = ProductVariant::findOrFail($request->variant_id);
        $factory      = Factory::findOrFail($request->factory_id);
        $location     = FactoryLocation::findOrFail($request->location_id);
        $customerType = CustomerType::findOrFail($request->customer_type_id);
        $badge        = $request->badge_id ? Badge::findOrFail($request->badge_id) : null;

        $priceDetails = $this->priceService->calculatePrice(
            $variant,
            $factory,
            $location,
            $customerType,
            $badge,
            $request->filter_categories,
            $request->boolean('with_vat', false)
        );

        $orderDetails = $this->priceService->calculateTotalPrice(
            $request->quantities,
            $priceDetails['final_price'],
            $request->boolean('with_vat', false)
        );


        $count   = $orderDetails['total_count'];
        $offers  = $this->checkEligibleOffers($variant->product, $count);
        $discounted = $this->applyDiscounts($orderDetails['subtotal'], $offers);

        $vatAmount = $request->boolean('with_vat', false)
            ? $discounted * ($orderDetails['vat_rate'] / 100)
            : 0;

        return response()->json([
            'status' => 'success',
            'data'   => [
                'unit_price'         => $priceDetails['final_price'],
                'unit_price_details' => $priceDetails,
                'order_details'      => $orderDetails,
                'subtotal'           => $orderDetails['subtotal'],
                'offers'             => $offers,
                'discount_amount'    => $orderDetails['subtotal'] - $discounted,
                'discounted_price'   => $discounted,
                'vat_amount'         => $vatAmount,
                'vat_rate'           => $request->boolean('with_vat', false) ? $orderDetails['vat_rate'] : 0,
                'final_price'        => $discounted + $vatAmount,
                'with_vat'           => $request->boolean('with_vat', false),
            ]
        ]);
    }


    private function applySorting($query, $sort)
    {
        switch ($sort) {
            case 'most_viewed':

                $query->orderBy(Product::VIEW_COUNT, 'desc');
                break;

            case 'newest':

                $query->orderBy(Product::CREATED_AT, 'desc');
                break;

            case 'best_selling':

                $query->withCount(['orderItems as sold_count'])
                    ->orderBy('sold_count', 'desc');
                break;

            case 'cheapest':

                $query->withMin('variants.prices', 'price')
                    ->orderBy('variants_prices_min_price', 'asc');
                break;

            case 'most_expensive':
                $query->withMax('variants.prices', 'price')
                    ->orderBy('variants_prices_max_price', 'desc');
                break;

            default:
                $query->latest();
        }
    }

    private function checkEligibleOffers($product, $totalQuantity)
    {
        return $product->offers()
            ->where('is_active', true)
            ->where('start_date', '<=', now())
            ->where(function ($query) {
                $query->where('end_date', '>=', now())
                    ->orWhereNull('end_date');
            })
            ->where('minimum_quantity', '<=', $totalQuantity)
            ->get();
    }

    private function applyDiscounts($totalPrice, $offers)
    {
        $discountedPrice = $totalPrice;

        foreach ($offers as $offer) {
            switch ($offer->type) {
                case 'percentage':
                    $discountedPrice -= ($discountedPrice * $offer->value / 100);
                    break;
                case 'fixed_amount':
                    $discountedPrice -= $offer->value;
                    break;
                case 'free_shipping':
                    break;
                case 'buy_x_get_y':
                    break;
            }
        }

        return max(0, $discountedPrice);
    }
}
