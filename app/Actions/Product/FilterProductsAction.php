<?php

namespace App\Actions\Product;

use App\DataTransferObjects\ProductFilterData;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Repositories\Contracts\ProductRepositoryInterface;
use App\Repositories\Eloquent\ProductRepository;
use App\Services\PriceCalculationService;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

class FilterProductsAction
{
    public function __construct(
        private readonly ProductRepositoryInterface $productRepo,
        private readonly PriceCalculationService $priceService
    ) {}

    /**
     * اجرای عملیات جستجو، محاسبه قیمت و صفحه‌بندی
     */
    public function execute(ProductFilterData $filters): LengthAwarePaginator
    {
        $products = $this->fetchBaseProducts($filters);
        $products->each(fn(Product $product) => $this->hydrateProductPrices($product, $filters));
        $sortedProducts = $this->applyDynamicSorting($products, $filters->sortKey);

        return $this->manualPaginate($sortedProducts, $filters->perPage);
    }

    private function fetchBaseProducts(ProductFilterData $filters): Collection
    {
        if ($this->productRepo instanceof ProductRepository) {
            return $this->productRepo->getFilterQuery($filters)->get();
        }
        
        return Product::query()
            ->when($filters->categoryId, fn($q) => $q->where('category_id', $filters->categoryId))
            ->get();
    }
    private function hydrateProductPrices(Product $product, ProductFilterData $filters): void
    {
        $product->variants->each(fn($v) => $this->hydrateVariantPrices($v, $filters));

        $product->min_variant_price = $product->variants
            ->flatMap(fn($v) => $v->prices)
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
                withVAT: false,
                filterCategoryCode: $filters->roleCode
            );

            $price->final_price        = $calcDto->finalPrice;
            $price->role_special_price = $calcDto->payablePrice;
            $price->role_general_price = $calcDto->listPrice;
            $price->role_golden_figure = max(0.0, $calcDto->listPrice - $calcDto->payablePrice);

            if ($filters->debugPrices) {
                $price->pricing_debug = $calcDto->toArray();
            }
        });

        $variant->min_price = $variant->prices->min('final_price');
    }

    private function applyDynamicSorting(Collection $collection, string $sortKey): Collection
    {
        $getter = fn($item) => $item->min_variant_price ?? 0;

        // تغییر اصلاحی: استفاده از متغیر واسط برای رفع خطای سینتکس
        $sorted = match ($sortKey) {
            'cheapest'       => $collection->sortBy($getter),
            'most_expensive' => $collection->sortByDesc($getter),
            'most_viewed'    => $collection->sortByDesc('view_count'),
            default          => $collection->sortByDesc('created_at'),
        };

        return $sorted->values();
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
}
