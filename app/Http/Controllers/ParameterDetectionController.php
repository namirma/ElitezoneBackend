<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Resources\PriceFilterCategoryResource;
use App\Models\Cart;
use App\Models\PriceFilterCategory;
use App\Models\PurchaseParameterValue;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ParameterDetectionController extends Controller
{
    /**
     * تشخیص مدل PriceFilterCategory بر اساس پارامترهای خرید
     *
     * @param int[] $paramIds
     * @return PriceFilterCategory|null
     */
    public function detectPriceFilterCategoryFromValues(array $paramIds): ?PriceFilterCategory
    {
        $values = PurchaseParameterValue::with(['group', 'priceFilterCategory'])
            ->whereIn('id', $paramIds)
            ->get()
            ->keyBy(fn($v) => $v->group->code);

        $ct = $values['customer_type'] ?? null;
        $cs = $values['consultant_status'] ?? null;
        $pp = $values['purchase_purpose'] ?? null;

        if ($ct && $cs) {
            if ($cs->code === 'with_consultant') {
                $prefix = strstr($ct->code, '_', true) ?: $ct->code;
                if ($combo = PriceFilterCategory::where('code', "{$prefix}_with_consultant")->first()) {
                    return $combo;
                }
            } else {
                if ($ct->priceFilterCategory) {
                    return $ct->priceFilterCategory;
                }

                $fallbackCode = "{$ct->code}_without_consultant";
                if ($fallback = PriceFilterCategory::where('code', $fallbackCode)->first()) {
                    return $fallback;
                }

                if ($fallback = PriceFilterCategory::where('code', $ct->code)->first()) {
                    return $fallback;
                }
            }
        }

        foreach ([$cs, $ct, $pp] as $v) {
            if ($v && $v->priceFilterCategory) {
                return $v->priceFilterCategory;
            }
        }

        return PriceFilterCategory::where('code', 'employer_without_consultant')->first();
    }

    /**
     * GET /api/price-filters/detect
     */
    public function detectPriceFilters(Request $request): JsonResponse
    {
        if ($request->filled('parameters') && is_array($request->input('parameters'))) {
            $ids = $request->input('parameters');
        } else {
            $cart = Cart::with('purchaseParameters')
                ->where('session_id', session()->getId())
                ->first();

            $ids = $cart?->purchaseParameters->pluck('id')->all() ?? [];
        }

        $category = $this->detectPriceFilterCategoryFromValues($ids)
            ?? PriceFilterCategory::where('code', 'employer_without_consultant')->first();

        return success(new PriceFilterCategoryResource($category));
    }
}
