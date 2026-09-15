<?php

namespace App\Actions\Product;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\ProductVariantAttribute;
use App\Services\Pricing\VariantPriceHydrator;
use App\Services\Product\AttributeFilterResolver;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

class FilterProductVariantsAction
{
    public function __construct(
        private readonly VariantPriceHydrator $priceHydrator,
        private readonly AttributeFilterResolver $attributeFilters,
    ) {}

    public function execute(Product $product, array $filters, string $roleCode): LengthAwarePaginator
    {
        // ۱. ساخت کوئری
        // 'cartItems' حذف شد: همهٔ ردیف‌های cart_items همهٔ کاربران را برای هر واریانت لود
        // می‌کرد، در حالی که نه در VariantPriceHydrator و نه در ProductVariantResource خوانده
        // می‌شود — اتلافِ حافظه/کوئری روی یک endpoint عمومیِ داغ.
        // واریانتی که درخواستِ تغییرِ قیمتِ باز دارد از کاتالوگ بیرون است
        // (تصمیم مدیریت ۲۰۲۶-۰۹-۰۹). با تأیید/رد/انقضا خودبه‌خود برمی‌گردد.
        $query = ProductVariant::query()
            ->where(ProductVariant::PRODUCT_ID, $product->getKey())
            ->catalogVisible()
            ->with(['prices.factory', 'prices.factory.activeProductFeatures', 'prices.factoryLocation', 'variantAttributes.attributeType', 'limitOrderUnit']);

        // ۲. اعمال فیلتر ویژگی‌ها
        //
        // ⚠️ دو سطحِ متفاوت، و سایدبار با **دومی** کار می‌کند:
        //   `filter_categories` → id گروه («گرید») — یعنی «هر واریانتی که اصلاً
        //      گرید دارد». به‌تنهایی تقریباً هیچ‌چیز را محدود نمی‌کند.
        //   `filter_values`     → id گزینه («A3») — چیزی که کاربر تیک می‌زند.
        //
        // پیش از این فقط اولی وجود داشت، پس تیکِ «A3» یا ۴۲۲ می‌گرفت (id در
        // جدولِ attributes نبود) یا بی‌اثر بود.
        if (! empty($filters['filter_categories'])) {
            $query->whereHas('variantAttributes', fn ($q) => $q->whereIn(ProductVariantAttribute::ATTRIBUTE_ID, $filters['filter_categories'])
            );
        }

        // یک `whereHas` به‌ازای هر گروه: داخلِ گروه OR، بینِ گروه‌ها AND.
        foreach ($this->attributeFilters->groupByAttribute($filters['filter_values'] ?? []) as $group) {
            $query->whereHas('variantAttributes', fn ($q) => $q->whereIn(
                'product_variant_attributes.'.ProductVariantAttribute::ATTRIBUTE_VALUE_ID,
                $group
            ));
        }

        // ۳. دریافت و محاسبه قیمت
        $variants = $query->get();

        // محاسبه قیمت برای تک‌تک واریانت‌ها
        $this->priceHydrator->hydrateMany($variants, $roleCode, $filters['debug_prices'] ?? false);

        // ۴. مرتب‌سازی
        $sortKey = $filters['sort'] ?? 'newest';
        $sortedVariants = $this->sortVariants($variants, $sortKey);

        // ۵. صفحه‌بندی
        return $this->paginate($sortedVariants, $filters['per_page'] ?? 10);
    }

    private function sortVariants(Collection $variants, string $sortKey): Collection
    {
        $getter = fn ($v) => $v->min_price ?? 0;

        $sorted = match ($sortKey) {
            'cheapest' => $variants->sortBy($getter),
            'most_expensive' => $variants->sortByDesc($getter),
            default => $variants->sortByDesc('created_at'),
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
