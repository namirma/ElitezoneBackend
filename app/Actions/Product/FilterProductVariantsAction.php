<?php

namespace App\Actions\Product;

use App\DataTransferObjects\ProductFilterData;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\ProductVariantAttribute;
use App\Services\PriceCalculationService;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

class FilterProductVariantsAction
{
    public function __construct(
        private readonly PriceCalculationService $priceService
    ) {}

    public function execute(Product $product, array $filters, string $roleCode): LengthAwarePaginator
    {
        // ۱. ساخت کوئری
        $query = $product->variants()
            ->with(['prices.factory', 'prices.factoryLocation', 'variantAttributes.attributeType', 'cartItems', 'limitOrderUnit']);

        // ۲. اعمال فیلتر ویژگی‌ها
        if (!empty($filters['filter_categories'])) {
            $query->whereHas('variantAttributes', fn($q) =>
                $q->whereIn(ProductVariantAttribute::ATTRIBUTE_ID, $filters['filter_categories'])
            );
        }

        // ۳. دریافت و محاسبه قیمت
        $variants = $query->get();

        // محاسبه قیمت برای تک‌تک واریانت‌ها
        $variants->each(fn($variant) => $this->hydrateVariantPrice($variant, $roleCode, $filters['debug_prices'] ?? false));

        // ۴. مرتب‌سازی
        $sortKey = $filters['sort'] ?? 'newest';
        $sortedVariants = $this->sortVariants($variants, $sortKey);

        // ۵. صفحه‌بندی
        return $this->paginate($sortedVariants, $filters['per_page'] ?? 10);
    }

   private function hydrateVariantPrice(ProductVariant $variant, string $roleCode, bool $debug): void
    {
        $variant->prices->each(function ($price) use ($variant, $roleCode, $debug) {
            $dto = $this->priceService->calculatePrice(
                variant: $variant,
                factory: $price->factory,
                location: $price->factoryLocation,
                customerType: null,
                badge: null,
                filterCategoryIds: null,
                withVAT: false,
                filterCategoryCode: $roleCode
            );

            $price->final_price = $dto->finalPrice;
            $price->role_special_price = $dto->payablePrice;
            $price->role_general_price = $dto->listPrice;
            $price->role_golden_figure = max(0.0, $dto->listPrice - $dto->payablePrice);
            $price->vat_rate = $dto->vatRate;


            if ($debug) {
                $price->pricing_debug = $dto->toArray();
            }
        });

        $variant->min_price = $variant->prices->min('final_price');
    }

    private function sortVariants(Collection $variants, string $sortKey): Collection
    {
        $getter = fn($v) => $v->min_price ?? 0;

        $sorted = match ($sortKey) {
            'cheapest'       => $variants->sortBy($getter),
            'most_expensive' => $variants->sortByDesc($getter),
            default          => $variants->sortByDesc('created_at'),
        };

        return $sorted->values();
    }

    private function paginate(Collection $items, int $perPage): LengthAwarePaginator
    {
        $page = LengthAwarePaginator::resolveCurrentPage();
        return new LengthAwarePaginator(
            $items->slice(($page - 1) * $perPage, $perPage)->values(),
            $items->count(),
            $perPage,
            $page,
            ['path' => LengthAwarePaginator::resolveCurrentPath()]
        );
    }
}
