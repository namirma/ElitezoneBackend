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
use App\Models\ProductPrice;
use App\Models\PriceFilter;
use App\Models\PriceFilterType;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class PriceCalculationService
{
    public function calculatePrice(
        ProductVariant  $variant,
        Factory         $factory,
        FactoryLocation $location,
        ?CustomerType   $customerType = null,
        ?Badge          $badge = null,
        ?array          $filterCategoryIds = null,
        bool            $withVAT = false,
        ?string         $filterCategoryCode = null
    ): array {
        $base = $this->getBasePrice($variant, $factory, $location);
        $initialGeneral = (float) $base['general_price'];
        $initialSpecial = (float) $base['special_price'];

        $discountBase = $initialSpecial > 0 ? $initialSpecial : $initialGeneral;
        $variantDiscount = $variant->getActiveDiscountDetails();
        $priceAfterVariantDiscount = $discountBase;
        $appliedVariantDiscountAmount = 0.0;
        $appliedVariantDiscountType  = null;
        $appliedVariantDiscountValue = null;

        if ($variantDiscount) {
            $appliedVariantDiscountType  = $variantDiscount['type'];
            $appliedVariantDiscountValue = (float) $variantDiscount['value'];

            if ($variantDiscount['type'] === 'percentage') {
                $appliedVariantDiscountAmount = $discountBase * ($appliedVariantDiscountValue / 100);
                $priceAfterVariantDiscount    = $discountBase - $appliedVariantDiscountAmount;
            } elseif ($variantDiscount['type'] === 'fixed_amount') {
                $appliedVariantDiscountAmount = $appliedVariantDiscountValue;
                $priceAfterVariantDiscount    = $discountBase - $appliedVariantDiscountValue;
            }
            $priceAfterVariantDiscount = max(0.0, $priceAfterVariantDiscount);
        }

        $filters = $this->getAllFilters($filterCategoryIds, $filterCategoryCode);

        $generalFilters  = $filters->filter(fn($f) => ($f->filterType->{PriceFilterType::APPLY_ON} ?? 'both') === 'general');
        $specialFilters  = $filters->filter(fn($f) => ($f->filterType->{PriceFilterType::APPLY_ON} ?? 'both') === 'special');
        $bothSideFilters = $filters->filter(fn($f) => ($f->filterType->{PriceFilterType::APPLY_ON} ?? 'both') === 'both');

        $generalWithFilters = $this->applyFilters($initialGeneral, $generalFilters);
        $specialWithFilters = $this->applyFilters($priceAfterVariantDiscount, $specialFilters);
        $customerAdjustment = $this->applyFilters(0.0, $bothSideFilters);

        $final = max(0.0, $specialWithFilters + $customerAdjustment);

        $vatRate = 0.0;
        $vatAmount = 0.0;
        if ($withVAT) {
            $vatRate   = PriceSetting::getValueAsFloat(PriceSetting::CODE_VAT_RATE, 10.0);
            $vatAmount = $final * ($vatRate / 100);
        }

        return [
            'base_general_price'           => (string) $initialGeneral,
            'base_special_price'           => (string) $initialSpecial,
            'variant_discount_type'        => $appliedVariantDiscountType,
            'variant_discount_value'       => $appliedVariantDiscountValue !== null ? (string) $appliedVariantDiscountValue : null,
            'variant_discount_amount'      => (string) $appliedVariantDiscountAmount,
            'price_after_variant_discount' => (string) $priceAfterVariantDiscount,
            'general_price_with_filters'   => (string) $generalWithFilters,
            'special_price_with_filters'   => (string) $specialWithFilters,
            'customer_adjustment'          => (string) $customerAdjustment,
            'role_golden_figure'           => (string) max(0.0, $generalWithFilters - $specialWithFilters),
            'final_price'                  => (string) $final,
            'vat_amount'                   => (string) $vatAmount,
            'vat_rate'                     => (string) ($withVAT ? $vatRate : 0.0),
            'price_with_vat'               => (string) ($final + $vatAmount),
            'with_vat'                     => $withVAT,
        ];
    }

    private function getAllFilters(?array $categoryIds, ?string $categoryCode = null): Collection
    {
        if ((empty($categoryIds) || !is_array($categoryIds)) && $categoryCode) {
            $categoryIds = PriceFilterCategory::where(PriceFilterCategory::CODE, $categoryCode)
                ->where(PriceFilterCategory::IS_ACTIVE, true)
                ->pluck('id')->all();
        }

        if (empty($categoryIds)) {
            return collect();
        }

        return PriceFilterCategory::whereIn('id', $categoryIds)
            ->where(PriceFilterCategory::IS_ACTIVE, true)
            ->with(['filters' => function ($q) {
                $q->where('price_filters.is_active', true)
                    ->join('price_filter_types as t', 't.id', '=', 'price_filters.price_filter_type_id')
                    ->orderBy('t.sort_order')
                    ->orderBy('price_filters.sort_order')
                    ->select('price_filters.*', 't.apply_on as _apply_on');
            }])
            ->get()
            ->flatMap(function (PriceFilterCategory $cat) {
                return $cat->filters->map(function ($f) {
                    $f->filterType = (object) ['apply_on' => $f->_apply_on];
                    return $f;
                });
            })
            ->unique('id');
    }

    private function applyFilters(float $startPrice, Collection $filters): float
    {
        $price = $startPrice;
        foreach ($filters->sortBy(PriceFilter::SORT_ORDER) as $filter) {
            $amount    = (float) $filter->{PriceFilter::AMOUNT};
            $isPercent = (bool)  $filter->{PriceFilter::IS_PERCENTAGE};
            $price = $isPercent ? $price + ($price * $amount / 100) : $price + $amount;
        }
        return $price;
    }

    private function getBasePrice(ProductVariant $variant, Factory $factory, FactoryLocation $location): array
    {
        $cacheKey = "price_{$variant->{ProductVariant::ID}}_{$factory->{Factory::ID}}_{$location->{FactoryLocation::ID}}";

        if (($cached = Cache::get($cacheKey)) !== null) {
            return $cached;
        }

        $priceModel = $variant->prices()
            ->where(ProductPrice::FACTORY_ID, $factory->id)
            ->where(ProductPrice::FACTORY_LOCATION_ID, $location->id)
            ->latest(ProductPrice::LAST_UPDATED_AT)
            ->first();

        $data = ['general_price' => 0.0, 'special_price' => 0.0];
        if ($priceModel) {
            $data = [
                'general_price' => (float) $priceModel->{ProductPrice::GENERAL_PRICE},
                'special_price' => (float) $priceModel->{ProductPrice::SPECIAL_PRICE},
            ];
        }

        Cache::put($cacheKey, $data, now()->addMinutes(15));
        return $data;
    }

    public function calculateTotalPrice(array $quantities, float $unitPrice, bool $withVAT = false): array
    {
        $totalCount = 0.0;
        $totalWeight = 0.0;
        $breakdown = [];
        $orderUnits = OrderUnit::all()->keyBy(OrderUnit::ID);

        foreach ($quantities as $unitIdToString => $qty) {
            $unitId = (int) $unitIdToString;
            $currentQty = (float) $qty;
            $unit = $orderUnits->get($unitId);
            if (!$unit || $currentQty <= 0) continue;

            $weightPerUnit = (float) $unit->{OrderUnit::AMOUNT};
            $totalCount += $currentQty;
            $totalWeight += $currentQty * $weightPerUnit;
            $breakdown[] = [
                'unit_id'      => $unit->id,
                'unit_name'    => $unit->{OrderUnit::NAME},
                'quantity'     => (string) $currentQty,
                'unit_weight'  => (string) $weightPerUnit,
                'total_weight' => (string) ($currentQty * $weightPerUnit),
            ];
        }

        $subtotal = $totalWeight * $unitPrice;
        $vatAmount = 0.0;
        $vatRate = 0.0;

        if ($withVAT) {
            $vatRate = PriceSetting::getValueAsFloat(PriceSetting::CODE_VAT_RATE, 10.0);
            $vatAmount = $subtotal * ($vatRate / 100);
        }

        return [
            'total_count'    => (string) $totalCount,
            'total_weight'   => (string) $totalWeight,
            'unit_price'     => (string) $unitPrice,
            'subtotal'       => (string) $subtotal,
            'vat_rate'       => (string) ($withVAT ? $vatRate : 0.0),
            'vat_amount'     => (string) $vatAmount,
            'total_with_vat' => (string) ($subtotal + $vatAmount),
            'with_vat'       => $withVAT,
            'breakdown'      => $breakdown,
        ];
    }
}
