<?php

namespace App\Http\Controllers;

use App\Actions\Order\CalculateOrderEstimateAction;
use App\Actions\Product\FilterProductsAction;
use App\Actions\Product\FilterProductVariantsAction;
use App\Http\Requests\Order\OrderPriceRequest;
use App\Http\Requests\Product\ProductIndexRequest;
use App\Http\Requests\Product\ProductVariantFilterRequest;
use App\Http\Requests\Product\ProductVariantPriceRequest;
use App\Http\Resources\Product\AttributeTypeResource;
use App\Http\Resources\Product\OfferResource;
use App\Http\Resources\Product\ProductDetailResource;
use App\Http\Resources\Product\ProductResource;
use App\Http\Resources\Product\ProductVariantResource;
use App\Http\Resources\Supplier\FactoryResource;
use App\Models\AttributeType;
use App\Models\Badge;
use App\Models\CustomerType;
use App\Models\Factory;
use App\Models\FactoryLocation;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Repositories\Contracts\ProductRepositoryInterface;
use App\Services\Cart\CartResolver;
use App\Services\PriceCalculationService;
use App\Services\Pricing\VariantPriceHydrator;
use App\Services\Product\AttributeFilterResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

class ProductController extends Controller
{
    public function __construct(
        private readonly CartResolver $cartResolver,
        private readonly ProductRepositoryInterface $productRepo,
        private readonly PriceCalculationService $priceService,
        private readonly AttributeFilterResolver $attributeFilters,
    ) {}

    public function index(ProductIndexRequest $request, FilterProductsAction $action): JsonResponse
    {
        $activeCode = $this->cartResolver->activeCode();

        // سایدبار ممکن است به‌جای `filter_values[]` برچسبِ گروه را به‌عنوان نامِ
        // پارامتر بفرستد؛ هر دو به idِ مقدار می‌رسند.
        $request->merge([
            'filter_values' => $this->attributeFilters->resolve($request, $request->input('filter_values', [])),
        ]);

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
            'aggregateLimitOrderUnit',
        ]);

        // ۲. ثبت بازدید و داده‌های تحلیلی (Async)
        \App\Jobs\Analytics\LogProductView::dispatch(
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
            'product' => new ProductDetailResource($product),
            'factories' => FactoryResource::collection($factories),
        ], 'global.received_successfully');
    }

    /**
     * لیست واریانت‌های یک محصول (برای انتخاب سایز/رنگ)
     */
    public function variants(ProductVariantFilterRequest $request, Product $product, FilterProductVariantsAction $action): JsonResponse
    {
        $activeCode = $this->cartResolver->activeCode();

        $filters = $request->validated();

        // همان قاعده‌ی لیستِ محصول: سایدبار ممکن است برچسبِ گروه را به‌عنوان نامِ
        // پارامتر بفرستد (`?سایز (قطر)=100`) به‌جای `filter_values[]`.
        $filters['filter_values'] = $this->attributeFilters->resolve(
            $request,
            $filters['filter_values'] ?? []
        );

        $paginator = $action->execute(
            product: $product,
            filters: $filters,
            roleCode: $activeCode
        );

        return success(
            ProductVariantResource::collection($paginator)->response()->getData(true),
            'global.received_successfully'
        );
    }

    /**
     * جزئیاتِ یک واریانتِ خاص با شناسه — مستقل از صفحه‌بندی و از اینکه به کدام
     * محصولِ اصلی تعلق دارد. دیپ‌لینکِ `/product/{id}` در فرانت قبلاً فقط داخلِ
     * لیستِ از-پیش-گرفته‌شده (صفحه‌ی اولِ واریانت‌های یک محصولِ اصلیِ خاص) جست‌وجو
     * می‌کرد؛ واریانتی که در صفحه‌ی بعدی بود یا به محصولِ اصلیِ دیگری تعلق داشت هرگز
     * پیدا نمی‌شد و «محصول موردنظر یافت نشد» نشان داده می‌شد.
     */
    public function showVariant(ProductVariant $variant, VariantPriceHydrator $priceHydrator): JsonResponse
    {
        $variant->load([
            'prices.factory',
            'prices.factory.activeProductFeatures',
            'prices.factoryLocation',
            'variantAttributes.attributeType',
            'limitOrderUnit',
            'product.category',
            'product.images',
            'product.orderUnits',
        ]);

        $priceHydrator->hydrate($variant, $this->cartResolver->activeCode());

        return success([
            'variant' => new ProductVariantResource($variant),
            'product' => new ProductResource($variant->product),
        ], 'global.received_successfully');
    }

    /**
     * دریافت ویژگی‌های قابل فیلتر برای یک محصول (مثل لیست رنگ‌ها)
     */
    public function filters(Product $product): JsonResponse
    {
        $attributeTypes = AttributeType::query()
            // فقط دسته‌بندی ویژگی‌هایی را بیاور که این محصول حداقل یک واریانت با آن ویژگی دارد
            ->whereHas('attributes.productVariants', function ($q) use ($product) {
                $q->where('product_id', $product->id);
            })
            ->with([
                'attributes' => function ($q) use ($product) {
                    // ۱. فیلتر خود ویژگی‌ها (Attributes) بر اساس موجود بودن در واریانت‌های این محصول
                    $q->whereHas('productVariants', function ($q2) use ($product) {
                        $q2->where('product_id', $product->id);
                    })
                        // ۲. لود کردن مقادیر (Values) منحصر به فردی که برای این محصول وجود دارد
                        ->with(['values' => function ($q3) use ($product) {
                            $q3->whereHas('productVariants', function ($q4) use ($product) {
                                $q4->where('product_id', $product->id);
                            })->distinct(); // جلوگیری از مقادیر تکراری
                        }]);
                },
            ])
            ->get();

        return success(
            AttributeTypeResource::collection($attributeTypes),
            'global.received_successfully'
        );
    }

    /**
     * محاسبه قیمت دقیق یک واریانت خاص
     */
    public function getVariantPrice(ProductVariantPriceRequest $request): JsonResponse
    {
        $data = $request->validated();
        $cart = $this->cartResolver->resolve($request);

        $variant = ProductVariant::findOrFail($data['variant_id']);
        $factory = Factory::findOrFail($data['factory_id']);
        $location = FactoryLocation::findOrFail($data['location_id']);

        $activeCode = $this->cartResolver->activeCode();
        $badge = $cart->badge_id ? Badge::find($cart->badge_id) : null;
        $customerType = CustomerType::where(CustomerType::CODE, $activeCode)->first();

        $priceDto = $this->priceService->calculatePrice(
            variant: $variant,
            factory: $factory,
            location: $location,
            customerType: $customerType,
            badge: $badge,
            filterCategoryIds: null,
            withVAT: (bool) ($data['with_vat'] ?? false),
            filterCategoryCode: $activeCode
        );

        return success($priceDto->toArray(), 'global.received_successfully');
    }

    /**
     * محاسبه پیش‌فاکتور نهایی سبد خرید/سفارش
     */
    public function calculateOrderPrice(OrderPriceRequest $request, CalculateOrderEstimateAction $action): JsonResponse
    {
        $activeCode = $this->cartResolver->activeCode();
        $cart = $this->cartResolver->resolve($request);
        $badge = ! empty($request->badge_id)
            ? Badge::find($request->badge_id)
            : ($cart->badge_id ? Badge::find($cart->badge_id) : null);

        $result = $action->execute($request->validated(), $activeCode, $badge);

        return success([
            'unit_price' => (string) $result['unit_dto']->finalPrice,
            'unit_price_details' => $result['unit_dto']->toArray(),
            'order_details' => $result['order_details'],
            'offers' => OfferResource::collection($result['offers']),
            ...$result['financials'],
        ], 'global.received_successfully');
    }
}
