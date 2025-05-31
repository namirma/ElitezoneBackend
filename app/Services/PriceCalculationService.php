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
        bool            $withVAT = false
    ): array {
        $basePrices = $this->getBasePrice($variant, $factory, $location);
        $initialGeneralPrice = $basePrices['general_price'];
        $initialSpecialPrice = $basePrices['special_price'];

        $priceToApplyVariantDiscountOn = $initialSpecialPrice > 0 ? $initialSpecialPrice : $initialGeneralPrice;

        $variantDiscountDetails = $variant->getActiveDiscountDetails();
        $priceAfterVariantDiscount = $priceToApplyVariantDiscountOn;
        $appliedVariantDiscountAmount = 0.0;
        $appliedVariantDiscountType = null;
        $appliedVariantDiscountValue = null;

        if ($variantDiscountDetails) {
            $appliedVariantDiscountType = $variantDiscountDetails['type'];
            $appliedVariantDiscountValue = $variantDiscountDetails['value'];
            $originalPriceForDiscountCalc = $priceToApplyVariantDiscountOn;

            if ($variantDiscountDetails['type'] === 'percentage') {
                $discountVal = $priceToApplyVariantDiscountOn * ($variantDiscountDetails['value'] / 100);
                $priceAfterVariantDiscount = $priceToApplyVariantDiscountOn - $discountVal;
                $appliedVariantDiscountAmount = $discountVal;
            } elseif ($variantDiscountDetails['type'] === 'fixed_amount') {
                $priceAfterVariantDiscount = $priceToApplyVariantDiscountOn - $variantDiscountDetails['value'];
                $appliedVariantDiscountAmount = $variantDiscountDetails['value'];
            }
            $priceAfterVariantDiscount = max(0.0, $priceAfterVariantDiscount);
        }

        $allFilters = $this->getAllFilters($filterCategoryIds);

        $generalFiltersFromCategory = $allFilters->filter(fn($filter) => $filter->filterType->{PriceFilterType::APPLY_ON} === 'general');
        $specialFiltersFromCategory = $allFilters->filter(fn($filter) => $filter->filterType->{PriceFilterType::APPLY_ON} === 'special');
        $adjustmentFiltersFromCategory = $allFilters->filter(fn($filter) => $filter->filterType->{PriceFilterType::APPLY_ON} === 'both');

        $generalPriceWithCatFilters = $this->applyFilters($initialGeneralPrice, $generalFiltersFromCategory);
        $specialPriceWithCatFilters = $this->applyFilters($priceAfterVariantDiscount, $specialFiltersFromCategory);
        $customerAdjustment = $this->applyFilters(0.0, $adjustmentFiltersFromCategory);

        $finalPrice = max(0.0, $specialPriceWithCatFilters + $customerAdjustment);

        $vatAmount = 0.0;
        $vatRatePercent = 0.0;
        if ($withVAT) {
            $vatRatePercent = PriceSetting::getValueAsFloat(PriceSetting::CODE_VAT_RATE, 10.0);
            $vatAmount = $finalPrice * ($vatRatePercent / 100);
        }

        return [
            'base_general_price'           => (string)$initialGeneralPrice,
            'base_special_price'           => (string)$initialSpecialPrice,
            'variant_discount_type'        => $appliedVariantDiscountType,
            'variant_discount_value'       => $appliedVariantDiscountValue !== null ? (string)$appliedVariantDiscountValue : null,
            'variant_discount_amount'      => (string)$appliedVariantDiscountAmount,
            'price_after_variant_discount' => (string)$priceAfterVariantDiscount,
            'general_price_with_filters'   => (string)$generalPriceWithCatFilters,
            'special_price_with_filters'   => (string)$specialPriceWithCatFilters,
            'customer_adjustment'          => (string)$customerAdjustment,
            'final_price'                  => (string)$finalPrice,
            'vat_amount'                   => (string)$vatAmount,
            'vat_rate'                     => (string)($withVAT ? $vatRatePercent : 0.0),
            'price_with_vat'               => (string)($finalPrice + $vatAmount),
            'with_vat'                     => $withVAT,
        ];
    }

    private function getAllFilters(?array $categoryIds): Collection
    {
        if (empty($categoryIds)) {
            return collect();
        }
        return PriceFilterCategory::whereIn(PriceFilterCategory::ID, $categoryIds)
            ->active()
            ->with(['filters.filterType'])
            ->get()
            ->flatMap(fn(PriceFilterCategory $category) => $category->filters)
            ->unique(PriceFilter::ID);
    }

    private function applyFilters(float $startPrice, Collection $filters): float
    {
        $price = $startPrice;
        $sortedFilters = $filters->sortBy(PriceFilter::SORT_ORDER);
        foreach ($sortedFilters as $filter) {
            $amountToApply = (float)$filter->{PriceFilter::AMOUNT};
            if ($filter->{PriceFilter::IS_PERCENTAGE}) {
                $price += $price * ($amountToApply / 100);
            } else {
                $price += $amountToApply;
            }
        }
        return $price;
    }

    private function getBasePrice(ProductVariant $variant, Factory $factory, FactoryLocation $location): array
    {
        $cacheKey = "price_{$variant->{ProductVariant::ID}}_{$factory->{Factory::ID}}_{$location->{FactoryLocation::ID}}";
        $cachedPrice = Cache::get($cacheKey);
        if ($cachedPrice !== null) {
            return $cachedPrice;
        }
        $priceModel = $variant->prices()
            ->where(ProductPrice::FACTORY_ID, $factory->id)
            ->where(ProductPrice::FACTORY_LOCATION_ID, $location->id)
            ->latest(ProductPrice::LAST_UPDATED_AT)
            ->first();
        $data = ['general_price' => 0.0, 'special_price' => 0.0];
        if ($priceModel) {
            $data = [
                'general_price' => (float)$priceModel->{ProductPrice::GENERAL_PRICE},
                'special_price' => (float)$priceModel->{ProductPrice::SPECIAL_PRICE},
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
        $orderUnitsMap = OrderUnit::all()->keyBy(OrderUnit::ID);

        foreach ($quantities as $unitIdToString => $qty) {
            $unitId = (int)$unitIdToString;
            $currentQty = (float)$qty;
            $unit = $orderUnitsMap->get($unitId);
            if (!$unit || $currentQty <= 0) continue;

            $weightPerUnit = (float)$unit->{OrderUnit::AMOUNT};
            $totalCount += $currentQty;
            $totalWeight += $currentQty * $weightPerUnit;
            $breakdown[] = [
                'unit_id'      => $unit->id,
                'unit_name'    => $unit->{OrderUnit::NAME},
                'quantity'     => (string)$currentQty,
                'unit_weight'  => (string)$weightPerUnit,
                'total_weight' => (string)($currentQty * $weightPerUnit),
            ];
        }
        $subtotal = $totalWeight * $unitPrice;
        $vatAmount = 0.0;
        $vatRatePercent = 0.0;
        if ($withVAT) {
            $vatRatePercent = PriceSetting::getValueAsFloat(PriceSetting::CODE_VAT_RATE, 10.0);
            $vatAmount = $subtotal * ($vatRatePercent / 100);
        }
        return [
            'total_count'    => (string)$totalCount,
            'total_weight'   => (string)$totalWeight,
            'unit_price'     => (string)$unitPrice,
            'subtotal'       => (string)$subtotal,
            'vat_rate'       => (string)($withVAT ? $vatRatePercent : 0.0),
            'vat_amount'     => (string)$vatAmount,
            'total_with_vat' => (string)($subtotal + $vatAmount),
            'with_vat'       => $withVAT,
            'breakdown'      => $breakdown,
        ];
    }
}
