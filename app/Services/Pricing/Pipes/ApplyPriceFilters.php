<?php

namespace App\Services\Pricing\Pipes;

use App\Models\PriceFilterCategory;
use App\Services\Pricing\PricingContext;
use Closure;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class ApplyPriceFilters implements PricingPipe
{
    public function handle(PricingContext $context, Closure $next)
    {
        // ۱. دریافت فیلترهای فعال برای این کاربر/محصول
        $filters = $this->getFilters($context);

        if ($filters->isEmpty()) {
            return $next($context);
        }

        $generalFilters = $filters->filter(fn ($f) => optional($f->filterType)->apply_on === 'general');
        $specialFilters = $filters->filter(fn ($f) => optional($f->filterType)->apply_on === 'special');
        $bothSideFilters = $filters->filter(fn ($f) => optional($f->filterType)->apply_on === 'both');

        if ($generalFilters->isNotEmpty()) {
            $context->listPrice = $this->applyFilters($context->listPrice, $generalFilters);
            $context->payablePrice = $this->applyFilters($context->payablePrice, $generalFilters);
        }

        // ۴. اعمال فیلترهای ویژه -> تاثیر روی قیمت قابل پرداخت (Payable Price)
        // مثال: قیمت 37000 - تخفیف مهندس 1000 = 36000
        if ($specialFilters->isNotEmpty()) {
            $context->payablePrice = $this->applyFilters($context->payablePrice, $specialFilters);
        }

        // ۵. اعمال فیلترهای دوطرفه (Adjustments)
        if ($bothSideFilters->isNotEmpty()) {
            // محاسبه مقدار خالص تغییرات (مثلاً +500 -200 = +300)
            $adjustment = $this->applyFilters(0.0, $bothSideFilters);
            $context->payablePrice += $adjustment;
        }

        // ۶. گارد نهایی: قیمت هرگز نباید منفی شود
        $context->payablePrice = max(0.0, $context->payablePrice);
        $context->listPrice = max(0.0, $context->listPrice);

        return $next($context);
    }

    /**
     * منطق ریاضی اعمال فیلترها
     */
    private function applyFilters(float $currentPrice, Collection $filters): float
    {
        // مانند بقیه pipe ها از bcmath استفاده می‌شود — جمع شدن خطای float
        // روی مبالغ میلیاردی (ریال) و فیلترهای زنجیره‌ای قابل قبول نیست.
        $priceStr = (string) $currentPrice;

        // مرتب‌سازی بر اساس اولویت (sort_order)
        foreach ($filters->sortBy('sort_order') as $filter) {
            $amountStr = (string) (float) $filter->amount;
            $isPercent = (bool) $filter->is_percentage;

            if ($isPercent) {
                $rateStr = bcdiv($amountStr, '100', 8);
                $changeStr = bcmul($priceStr, $rateStr, 4);
                $priceStr = bcadd($priceStr, $changeStr, 4);
            } else {
                $priceStr = bcadd($priceStr, $amountStr, 4);
            }
        }

        return (float) $priceStr;
    }

    /**
     * تمام دسته‌بندی‌های فعال + فیلترهایشان را از کش یا دیتابیس برمی‌گرداند.
     * یک کلید واحد → invalidation ساده در model observer.
     */
    private function allCategoriesWithFilters(): Collection
    {
        return Cache::remember('pricing:categories_with_filters', 3600, fn () => PriceFilterCategory::where('is_active', true)
            ->with(['filters.filterType'])
            ->get()
        );
    }

    /**
     * دریافت لیست فیلترها برای context جاری (از کش)
     */
    private function getFilters(PricingContext $context): Collection
    {
        $ids = $context->filterCategoryIds;

        if (empty($ids) && $context->filterCategoryCode) {
            $category = $this->allCategoriesWithFilters()
                ->firstWhere('code', $context->filterCategoryCode);

            if ($category) {
                $ids = [$category->id];
            }
        }

        if (empty($ids)) {
            return collect();
        }

        return $this->allCategoriesWithFilters()
            ->whereIn('id', $ids)
            ->flatMap(fn ($cat) => $cat->filters)
            ->filter(fn ($f) => $f && $f->is_active && $f->filterType)
            ->unique('id')
            ->values();
    }
}
