<?php

namespace App\Services;

use App\Models\ProductVariant;
use App\Models\Factory;
use App\Models\FactoryLocation;
use App\Models\CustomerType;
use App\Models\Badge;
use App\Models\PriceFilterCategory;
use App\Models\PriceSetting;
use App\Models\OrderUnit;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class PriceCalculationService
{
    /**
     * 
     *
     * @param ProductVariant
     * @param Factory
     * @param FactoryLocation
     * @param CustomerType|null
     * @param Badge|null
     * @param int[]|null
     * @param bool
     *
     * @return array
     */
    public function calculatePrice(
        ProductVariant  $variant,
        Factory         $factory,
        FactoryLocation $location,
        ?CustomerType   $customerType     = null,
        ?Badge          $badge            = null,
        ?array          $filterCategories = null,
        bool            $withVAT          = false
    ): array {
        // دریافت قیمت پایه
        $base = $this->getBasePrice($variant, $factory, $location);

        // جمع‌آوری فیلترها
        $filters = $this->getAllFilters($filterCategories);

        // جداسازی فیلترها
        $generalFilters    = $filters->filter(fn($f) => $f->filterType->apply_on === 'general');
        $specialFilters    = $filters->filter(fn($f) => $f->filterType->apply_on === 'special');
        $adjustmentFilters = $filters->filter(fn($f) => $f->filterType->apply_on === 'both');

        // اعمال فیلترهای عمومی
        $generalWith = $this->applyFilters((float)$base['general_price'], $generalFilters);

        // اعمال فیلترهای اختصاصی
        $specialWith = $this->applyFilters((float)$base['special_price'], $specialFilters);

        // محاسبه تنظیم قیمت مشتری
        $adjustment = $this->applyFilters(0, $adjustmentFilters);

        // قیمت نهایی بدون VAT
        $final = max(0, $specialWith - $adjustment);

        // محاسبه VAT
        $vatAmount      = 0;
        $vatRatePercent = 0;
        if ($withVAT) {
            $vatRatePercent = PriceSetting::getValueAsFloat('vat_rate', 10);
            $vatAmount      = $final * ($vatRatePercent / 100);
        }

        // بازگردانی مقادیر به صورت رشته
        return [
            'base_general_price'         => (string)$base['general_price'],
            'base_special_price'         => (string)$base['special_price'],
            'general_price_with_filters' => (string)$generalWith,
            'special_price_with_filters' => (string)$specialWith,
            'customer_adjustment'        => (string)$adjustment,
            'final_price'                => (string)$final,
            'vat_amount'                 => (string)$vatAmount,
            'vat_rate'                   => (string)($withVAT ? $vatRatePercent : 0),
            'price_with_vat'             => (string)($final + $vatAmount),
            'with_vat'                   => $withVAT,
        ];
    }

    /**
     * دریافت فیلترها از دسته‌های قیمت
     */
    private function getAllFilters(?array $catIds): Collection
    {
        if (empty($catIds)) {
            return collect();
        }

        return PriceFilterCategory::whereIn('id', $catIds)
            ->active()
            ->with(['filters.filterType'])
            ->get()
            ->flatMap->filters
            ->unique('id');
    }

    /**
     * اعمال فیلترها روی قیمت
     */
    private function applyFilters(float $startPrice, Collection $filters): float
    {
        $price = $startPrice;
        foreach ($filters as $filter) {
            $price += $filter->is_percentage
                ? $price * ($filter->amount / 100)
                : $filter->amount;
        }
        return max(0, $price);
    }

    /**
     * دریافت قیمت پایه
     */
    private function getBasePrice(ProductVariant $variant, Factory $factory, FactoryLocation $location): array
    {
        $cacheKey = "price_{$variant->id}_{$factory->id}_{$location->id}";
        if (Cache::has($cacheKey)) {
            return Cache::get($cacheKey);
        }

        $model = $variant->prices()
            ->where('factory_id', $factory->id)
            ->where('factory_location_id', $location->id)
            ->latest('last_updated_at')
            ->first();

        $data = ['general_price' => 0.0, 'special_price' => 0.0];
        if ($model) {
            $data = [
                'general_price' => (float)$model->general_price,
                'special_price' => (float)$model->special_price,
            ];
            Cache::put($cacheKey, $data, now()->addMinutes(15));
        }

        return $data;
    }

    /**
     * محاسبهٔ قیمت کل با VAT و جزئیات واحدها
     */
    public function calculateTotalPrice(array $quantities, float $unitPrice, bool $withVAT = false): array
    {
        $totalCount  = 0;
        $totalWeight = 0;
        $breakdown   = [];

        foreach ($quantities as $unitId => $qty) {
            $unit = OrderUnit::find($unitId);
            if (! $unit) continue;

            $weight       = (float)$unit->amount;
            $totalCount  += $qty;
            $totalWeight += $qty * $weight;

            $breakdown[] = [
                'unit_id'      => $unit->id,
                'unit_name'    => $unit->name,
                'quantity'     => (string)$qty,
                'unit_weight'  => (string)$weight,
                'total_weight' => (string)($qty * $weight),
            ];
        }

        $subtotal      = $totalWeight * $unitPrice;
        $vatAmount     = 0;
        $vatRatePercent = 0;
        if ($withVAT) {
            $vatRatePercent = PriceSetting::getValueAsFloat('vat_rate', 10);
            $vatAmount      = $subtotal * ($vatRatePercent / 100);
        }

        return [
            'total_count'    => (string)$totalCount,
            'total_weight'   => (string)$totalWeight,
            'unit_price'     => (string)$unitPrice,
            'subtotal'       => (string)$subtotal,
            'vat_rate'       => (string)($withVAT ? $vatRatePercent : 0),
            'vat_amount'     => (string)$vatAmount,
            'total_with_vat' => (string)($subtotal + $vatAmount),
            'with_vat'       => $withVAT,
            'breakdown'      => $breakdown,
        ];
    }
}
