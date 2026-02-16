<?php

namespace App\Services\Pricing\Pipes;

use App\Models\PriceFilterCategory;
use App\Services\Pricing\PricingContext;
use Closure;
use Illuminate\Support\Collection;

class ApplyPriceFilters implements PricingPipe
{
    public function handle(PricingContext $context, Closure $next)
    {
        // ۱. دریافت فیلترهای فعال برای این کاربر/محصول
        $filters = $this->getFilters($context);

        if ($filters->isEmpty()) {
            return $next($context);
        }

        $generalFilters = $filters->filter(fn($f) => optional($f->filterType)->apply_on === 'general');
        $specialFilters = $filters->filter(fn($f) => optional($f->filterType)->apply_on === 'special');
        $bothSideFilters = $filters->filter(fn($f) => optional($f->filterType)->apply_on === 'both');

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
        // مرتب‌سازی بر اساس اولویت (sort_order)
        foreach ($filters->sortBy('sort_order') as $filter) {
            $amount = (float) $filter->amount;
            $isPercent = (bool) $filter->is_percentage;


            if ($isPercent) {

                $change = $currentPrice * ($amount / 100);
                $currentPrice += $change;
            } else {

                $currentPrice += $amount;
            }
        }

        return $currentPrice;
    }

    /**
     * دریافت لیست فیلترها از دیتابیس
     */
    private function getFilters(PricingContext $context): Collection
    {
        $ids = $context->filterCategoryIds;


        if (empty($ids) && $context->filterCategoryCode) {
            $category = PriceFilterCategory::where('code', $context->filterCategoryCode)
                ->where('is_active', true)
                ->first();

            if ($category) {
                $ids = [$category->id];
            }
        }

        if (empty($ids)) {
            return collect();
        }

        return PriceFilterCategory::whereIn('id', $ids)
            ->where('is_active', true)
            ->with(['filters.filterType']) 
            ->get()
            ->flatMap(function ($category) {
                return $category->filters;
            })
            // حذف فیلترهای غیرفعال یا ناقص
            ->filter(fn($f) => $f && $f->is_active && $f->filterType)
            ->unique('id')
            ->values();
    }
}
