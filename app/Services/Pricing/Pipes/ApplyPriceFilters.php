<?php

namespace App\Services\Pricing\Pipes;

use App\Models\PriceFilter;
use App\Models\PriceFilterCategory;
use App\Models\PriceFilterType;
use App\Services\Pricing\PricingContext;
use Closure;
use Illuminate\Support\Collection;

class ApplyPriceFilters implements PricingPipe
{
    public function handle(PricingContext $context, Closure $next)
    {
        $filters = $this->getFilters($context);

        // تفکیک فیلترها
        $generalFilters = $filters->filter(fn($f) => ($f->filterType->apply_on ?? 'both') === 'general');
        $specialFilters = $filters->filter(fn($f) => ($f->filterType->apply_on ?? 'both') === 'special');
        $bothSideFilters = $filters->filter(fn($f) => ($f->filterType->apply_on ?? 'both') === 'both');

        // ۱. اعمال فیلترهای عمومی روی List Price
        $context->listPrice = $this->apply($context->listPrice, $generalFilters);

        // ۲. اعمال فیلترهای ویژه روی Payable Price
        $context->payablePrice = $this->apply($context->payablePrice, $specialFilters);


        $context->customerAdjustment = $this->apply(0.0, $bothSideFilters);

        // اضافه کردن adjustment به قیمت نهایی
        $context->payablePrice += $context->customerAdjustment;
        $context->payablePrice = max(0.0, $context->payablePrice);

        return $next($context);
    }

    private function apply(float $price, Collection $filters): float
    {
        foreach ($filters->sortBy('sort_order') as $filter) {
            $amount = (float) $filter->amount;
            $isPercent = (bool) $filter->is_percentage;

            if ($isPercent) {
                $price += ($price * $amount / 100);
            } else {
                $price += $amount;
            }
        }
        return $price;
    }

    private function getFilters(PricingContext $context): Collection
    {
        $ids = $context->filterCategoryIds;

        // اگر آیدی نیست ولی کد هست، پیدا کن
        if ((empty($ids) || !is_array($ids)) && $context->filterCategoryCode) {
            $ids = PriceFilterCategory::where('code', $context->filterCategoryCode)
                ->where('is_active', true)
                ->pluck('id')
                ->all();
        }

        if (empty($ids)) {
            return collect();
        }

        // کوئری برای دریافت فیلترها (کپی شده از لاجیک اصلی)
        return PriceFilterCategory::whereIn('id', $ids)
            ->where('is_active', true)
            ->with(['filters' => function ($q) {
                $q->where('price_filters.is_active', true)
                    ->join('price_filter_types as t', 't.id', '=', 'price_filters.price_filter_type_id')
                    ->orderBy('t.sort_order')
                    ->orderBy('price_filters.sort_order')
                    ->select('price_filters.*', 't.apply_on as _apply_on');
            }])
            ->get()
            ->flatMap(function ($cat) {
                return $cat->filters->map(function ($f) {
                    $f->filterType = (object) ['apply_on' => $f->_apply_on];
                    return $f;
                });
            })
            ->unique('id');
    }
}
