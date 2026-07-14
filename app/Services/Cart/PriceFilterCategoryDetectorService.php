<?php

namespace App\Services\Cart;

use App\Models\PriceFilterCategory;
use App\Models\PurchaseParameterValue;

/**
 * Determines which PriceFilterCategory applies given a set of purchase
 * parameter IDs selected by the user (customer_type / consultant_status /
 * purchase_purpose).
 *
 * Extracted from ParameterDetectionController so it can be reused by
 * CartParameterSyncService without cross-controller invocation.
 */
class PriceFilterCategoryDetectorService
{
    public function detectFromParameterIds(array $parameterIds): ?PriceFilterCategory
    {
        $parameterValues = PurchaseParameterValue::with(['group', 'priceFilterCategory'])
            ->whereIn(PurchaseParameterValue::ID, $parameterIds)
            ->get()
            ->keyBy(fn ($value) => $value->group->code);

        $customerTypeValue    = $parameterValues['customer_type']      ?? null;
        $eliteYarStatusValue = $parameterValues['consultant_status'] ?? null;
        $purchasePurposeValue  = $parameterValues['purchase_purpose']  ?? null;

        if ($customerTypeValue && $eliteYarStatusValue) {
            if ($eliteYarStatusValue->code === 'with_consultant') {
                $prefix   = strstr($customerTypeValue->code, '_', true) ?: $customerTypeValue->code;
                $category = PriceFilterCategory::where(PriceFilterCategory::CODE, "{$prefix}_with_consultant")->first();
                if ($category) {
                    return $category;
                }
            } else {
                if ($customerTypeValue->priceFilterCategory) {
                    return $customerTypeValue->priceFilterCategory;
                }

                $fallbackCode = "{$customerTypeValue->code}_without_consultant";
                $category     = PriceFilterCategory::where(PriceFilterCategory::CODE, $fallbackCode)->first();
                if ($category) {
                    return $category;
                }

                $category = PriceFilterCategory::where(PriceFilterCategory::CODE, $customerTypeValue->code)->first();
                if ($category) {
                    return $category;
                }
            }
        }

        foreach ([$eliteYarStatusValue, $customerTypeValue, $purchasePurposeValue] as $value) {
            if ($value && $value->priceFilterCategory) {
                return $value->priceFilterCategory;
            }
        }

        return PriceFilterCategory::where(PriceFilterCategory::CODE, 'employer_without_consultant')->first();
    }
}
