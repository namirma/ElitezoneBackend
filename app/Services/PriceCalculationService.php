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
     * محاسبه قیمت واریانت محصول با اعمال فیلترها
     */
    public function calculatePrice(
        ProductVariant $variant,
        Factory $factory,
        FactoryLocation $location,
        CustomerType $customerType,
        ?Badge $badge = null,
        ?array $filterCategories = null,
        bool $withVAT = false
    ): array {
        // 1) قیمت پایه
        $base = $this->getBasePrice($variant, $factory, $location);

        // 2) اعمال فیلترها روی general و special
        $generalWith = $this->applyGeneralFilters($base['general_price'], $filterCategories);
        $specialWith = $this->applySpecialFilters($base['special_price'], $filterCategories);

        // 3) تنظیم مشتری/نشان
        $adj = $this->getPriceAdjustment($customerType, $badge);

        // 4) قیمت نهایی بدون VAT
        $final = $specialWith - $adj;

        // 5) VAT
        $vatAmount = 0;
        if ($withVAT) {
            $rate = PriceSetting::getValueAsFloat('vat_rate', 10) / 100;
            $vatAmount = $final * $rate;
        }

        return [
            'base_general_price'           => $base['general_price'],
            'base_special_price'           => $base['special_price'],
            'golden_figure'                => $base['golden_figure'],
            'general_price_with_filters'   => $generalWith,
            'special_price_with_filters'   => $specialWith,
            'customer_adjustment'          => $adj,
            'final_price'                  => $final,
            'vat_amount'                   => $vatAmount,
            'vat_rate'                     => $withVAT ? PriceSetting::getValueAsFloat('vat_rate', 10) : 0,
            'price_with_vat'               => $final + $vatAmount,
            'with_vat'                     => $withVAT,
        ];
    }

    private function getBasePrice(ProductVariant $variant, Factory $factory, FactoryLocation $location): array
    {
        $key = "price_{$variant->id}_{$factory->id}_{$location->id}";
        if (Cache::has($key)) {
            return Cache::get($key);
        }

        $price = $variant->prices()
            ->where('factory_id', $factory->id)
            ->where('factory_location_id', $location->id)
            ->latest('last_updated_at')
            ->first()
            ?: $this->fetchPriceFromFactoryApi($variant, $factory, $location);

        $data = [
            'general_price' => $price->general_price,
            'special_price' => $price->special_price,
            'golden_figure' => $price->golden_figure,
        ];

        Cache::put($key, $data, now()->addMinutes(15));
        return $data;
    }

    private function applyGeneralFilters(float $price, ?array $cats): float
    {
        if (empty($cats)) return $price;
        $filters = $this->getFiltersFromCategories($cats, 'general');
        foreach ($filters as $f) {
            $price += $f->is_percentage
                ? $price * ($f->amount / 100)
                : $f->amount;
        }
        return max(0, $price);
    }

    private function applySpecialFilters(float $price, ?array $cats): float
    {
        if (empty($cats)) return $price;
        $filters = $this->getFiltersFromCategories($cats, 'special');
        foreach ($filters as $f) {
            $price += $f->is_percentage
                ? $price * ($f->amount / 100)
                : $f->amount;
        }
        return max(0, $price);
    }

    private function getFiltersFromCategories(array $cats, string $type): Collection
    {
        return PriceFilterCategory::whereIn('id', $cats)
            ->active()
            ->with(['filters.filterType'])
            ->get()
            ->flatMap->filters
            ->filter(fn($f) => $f->filterType->apply_on === $type)
            ->unique('id');
    }

    private function getPriceAdjustment(CustomerType $ct, ?Badge $badge): int
    {
        return \App\Models\PriceAdjustment::where('customer_type_id', $ct->id)
            ->where(fn($q) => $q
                ->where('badge_id', $badge?->id)
                ->orWhereNull('badge_id')
            )
            ->where('is_active', true)
            ->value('adjustment_amount') ?: 0;
    }

    public function calculateTotalPrice(array $quantities, float $unitPrice, bool $withVAT = false): array
    {
        $totalCount  = 0;
        $totalWeight = 0;
        $breakdown   = [];

        foreach ($quantities as $unitId => $qty) {
            $unit = OrderUnit::find($unitId);
            if (! $unit) continue;

            $w = (float) $unit->amount;  // وزن هر واحد
            $totalCount  += $qty;
            $totalWeight += $qty * $w;

            $breakdown[] = [
                'unit_id'      => $unit->id,
                'unit_name'    => $unit->name,
                'quantity'     => $qty,
                'unit_weight'  => $w,
                'total_weight' => $qty * $w,
            ];
        }

        // **دقت کنید**: مبلغ کل = totalWeight × unitPrice
        $subtotal = $totalWeight * $unitPrice;

        $vatAmount = 0;
        if ($withVAT) {
            $rate = PriceSetting::getValueAsFloat('vat_rate', 10) / 100;
            $vatAmount = $subtotal * $rate;
        }

        return [
            'total_count'    => $totalCount,
            'total_weight'   => $totalWeight,
            'unit_price'     => $unitPrice,
            'subtotal'       => $subtotal,
            'vat_rate'       => $withVAT ? PriceSetting::getValueAsFloat('vat_rate', 10) : 0,
            'vat_amount'     => $vatAmount,
            'total_with_vat' => $subtotal + $vatAmount,
            'with_vat'       => $withVAT,
            'breakdown'      => $breakdown,
        ];
    }

    // ... بقیهٔ متدهای fetchPriceFromFactoryApi و غیره بدون تغییر
}
