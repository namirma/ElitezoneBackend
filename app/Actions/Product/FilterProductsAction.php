<?php

namespace App\Actions\Product;

use App\DataTransferObjects\Product\ProductFilterData;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\ProductVariantAttribute;
use App\Models\SupplierProfile;
use App\Repositories\Contracts\ProductRepositoryInterface;
use App\Services\PriceCalculationService;
use App\Services\Product\AttributeFilterResolver;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

class FilterProductsAction
{
    public function __construct(
        private readonly ProductRepositoryInterface $productRepo,
        private readonly PriceCalculationService $priceService,
        private readonly AttributeFilterResolver $attributeFilters,
    ) {}

    /**
     * اجرای عملیات جستجو، محاسبه قیمت و صفحه‌بندی
     */
    public function execute(ProductFilterData $filters): LengthAwarePaginator
    {
        $products = $this->fetchBaseProducts($filters);
        $products->each(fn (Product $product) => $this->hydrateProductPrices($product, $filters));
        $sortedProducts = $this->applyDynamicSorting($products, $filters->sortKey);

        return $this->manualPaginate($sortedProducts, $filters->perPage);
    }

    private function fetchBaseProducts(ProductFilterData $filters): Collection
    {
        $relations = [
            'category',
            'images',
            // واریانتِ «در انتظار تأیید قیمت» در فهرست نمی‌آید و در کمینه‌ی قیمتِ
            // محصول هم شمرده نمی‌شود (تصمیم مدیریت ۲۰۲۶-۰۹-۰۹).
            'variants' => fn ($q) => $q->catalogVisible(),
            // ردیفِ قیمتِ تأمین‌کننده‌ی منقضی به سایت نمی‌رسد (`scopeFromLiveSuppliers`).
            'variants.prices' => fn ($q) => $q->fromLiveSuppliers(),
            'variants.prices.factory',
            'variants.prices.factory.activeProductFeatures',
            'variants.prices.factoryLocation',
            'orderUnits',
        ];

        $query = Product::query()->with($relations);

        $query->when($filters->categoryId, fn ($q) => $q->where('category_id', $filters->categoryId));

        // جستجوی محصول روی نام و SKU — قبلاً پارامتر search اعتبارسنجی و حمل می‌شد
        // اما در کوئری هرگز استفاده نمی‌شد (سرچ عملاً بی‌اثر بود و کل لیست برمی‌گشت).
        $query->when($filters->search, function ($q) use ($filters) {
            $term = '%'.$filters->search.'%';
            $q->where(function ($sub) use ($term) {
                $sub->where(Product::NAME, 'like', $term)
                    ->orWhere(Product::SKU, 'like', $term);
            });
        });

        // فیلترِ دسته‌های ویژگی (filter_categories) — محصولاتی که واریانتی با این
        // attributeها دارند. قبلاً این پارامتر هم بی‌اثر بود (فقط در لیستِ واریانت‌ها اعمال می‌شد).
        $query->when(! empty($filters->filterCategories), function ($q) use ($filters) {
            $q->whereHas('variants.variantAttributes', function ($sub) use ($filters) {
                $sub->whereIn(ProductVariantAttribute::ATTRIBUTE_ID, $filters->filterCategories);
            });
        });

        // گزینه‌ی مشخص («A3») — همان سطحی که سایدبار تیک می‌زند. `filter_categories`
        // فقط گروه است و به‌تنهایی چیزی را محدود نمی‌کند.
        // یک `whereHas` به‌ازای هر گروه: داخلِ گروه OR، بینِ گروه‌ها AND — وگرنه
        // تیکِ دوم نتیجه را پهن‌تر می‌کرد به‌جای باریک‌تر.
        foreach ($this->attributeFilters->groupByAttribute($filters->filterValues ?? []) as $group) {
            $query->whereHas('variants.variantAttributes', function ($sub) use ($group) {
                $sub->whereIn(
                    'product_variant_attributes.'.ProductVariantAttribute::ATTRIBUTE_VALUE_ID,
                    $group
                );
            });
        }

        $query->when($filters->availability === 'in_stock', function ($q) {
            $q->whereHas('variants', function ($variantQuery) {
                // ستون واقعی «stock_quantity» است؛ «stock» وجود ندارد و باعث کرشِ
                // «Unknown column 'stock'» (خطای ۵۰۰) روی فیلتر موجودی می‌شد.
                $variantQuery->where(ProductVariant::STOCK_QUANTITY, '>', 0);
            });
        });
        $query->when($filters->availability === 'out_of_stock', function ($q) {
            $q->whereDoesntHave('variants', function ($variantQuery) {
                $variantQuery->where(ProductVariant::STOCK_QUANTITY, '>', 0);
            });
        });

        $this->hideProductsWithExpiredPrices($query);

        return $query->get();
    }

    /**
     * محصولاتی که هیچ قیمتِ **معتبری** ندارند از لیست عمومی حذف می‌شوند — بند ۳.۲.
     *
     * «معتبر» یعنی دستِ‌کم یک ردیفِ قیمت از کارخانه‌ای که تأمین‌کننده‌اش
     * `reserve_time` زنده دارد. با گذشتنِ آن لحظه و نیامدنِ قیمتِ جدید،
     * محصول باید ناپدید شود تا کسی روی عددِ کهنه تصمیم نگیرد.
     *
     * محصولی که اصلاً هیچ ردیفِ قیمتی ندارد دست‌نخورده می‌ماند: پنهان‌کردنش
     * کارِ این قاعده نیست و `AddToCartAction` جداگانه جلوی قیمتِ صفر را
     * می‌گیرد. اینجا فقط «قیمت داشت و منقضی شد» را می‌بندیم.
     */
    private function hideProductsWithExpiredPrices($query): void
    {
        // شناسه‌ی تأمین‌کننده‌هایی که همین حالا پنجره‌ی قیمتِ معتبر دارند.
        // زیرکوئری و نه `whereHas` زنجیره‌ای، چون `Factory` رابطه‌ای به
        // `SupplierProfile` ندارد — فقط ستونِ `supplier_user_id`.
        $validSuppliers = SupplierProfile::query()
            ->whereNotNull(SupplierProfile::RESERVE_TIME)
            ->where(SupplierProfile::RESERVE_TIME, '>', now())
            ->select(SupplierProfile::USER_ID);

        $query->where(function ($outer) use ($validSuppliers) {
            // محصولِ بدونِ هیچ ردیفِ قیمت دست‌نخورده می‌ماند: پنهان‌کردنش کارِ
            // این قاعده نیست و گاردِ «قیمت صفر» در AddToCartAction جلویش را
            // می‌گیرد. اینجا فقط «قیمت داشت و منقضی شد» بسته می‌شود.
            $outer->whereDoesntHave('variants.prices')
                ->orWhereHas('variants.prices.factory', function ($factoryQuery) use ($validSuppliers) {
                    $factoryQuery->whereIn('supplier_user_id', $validSuppliers);
                });
        });
    }

    private function hydrateProductPrices(Product $product, ProductFilterData $filters): void
    {
        $product->variants->each(fn ($v) => $this->hydrateVariantPrices($v, $filters));

        $product->min_variant_price = $product->variants
            ->flatMap(fn ($v) => $v->prices)
            ->min('final_price');
    }

    private function hydrateVariantPrices(ProductVariant $variant, ProductFilterData $filters): void
    {
        $variant->prices->each(function ($price) use ($variant, $filters) {
            $calcDto = $this->priceService->calculatePrice(
                variant: $variant,
                factory: $price->factory,
                location: $price->factoryLocation,
                customerType: null,
                badge: null,
                filterCategoryIds: null,
                withVAT: $filters->withVat,
                filterCategoryCode: $filters->roleCode
            );

            $price->final_price = $calcDto->finalPrice;
            $price->role_special_price = $calcDto->payablePrice;
            $price->role_general_price = $calcDto->listPrice;
            $price->role_golden_figure = max(0.0, $calcDto->listPrice - $calcDto->payablePrice);
            // «تخفیف x٪» روی کارتِ محصول — فقط گروهِ دارای الیت‌یار (مدیریت، دورِ پنجم، پرسشِ ۱).
            $price->buyer_discount_percent = $calcDto->buyerDiscountPercent;

            if ($filters->debugPrices) {
                $price->pricing_debug = $calcDto->toArray();
            }
        });

        $variant->min_price = $variant->prices->min('final_price');
    }

    private function applyDynamicSorting(Collection $collection, string $sortKey): Collection
    {
        $getter = fn ($item) => $item->min_variant_price ?? 0;

        // تغییر اصلاحی: استفاده از متغیر واسط برای رفع خطای سینتکس
        $sorted = match ($sortKey) {
            'cheapest' => $collection->sortBy($getter),
            'most_expensive' => $collection->sortByDesc($getter),
            'most_viewed' => $collection->sortByDesc('view_count'),
            default => $collection->sortByDesc('created_at'),
        };

        return $sorted->values();
    }

    private function manualPaginate(Collection $items, int $perPage): LengthAwarePaginator
    {
        $currentPage = LengthAwarePaginator::resolveCurrentPage();
        $slice = $items->slice(($currentPage - 1) * $perPage, $perPage)->values();

        return new LengthAwarePaginator(
            $slice,
            $items->count(),
            $perPage,
            $currentPage,
            ['path' => LengthAwarePaginator::resolveCurrentPath()]
        );
    }
}
