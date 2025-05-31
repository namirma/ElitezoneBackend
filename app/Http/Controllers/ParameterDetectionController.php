<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Resources\PriceFilterCategoryResource;
use App\Models\Cart;
use App\Models\PriceFilterCategory;
use App\Models\PurchaseParameterValue;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ParameterDetectionController extends Controller
{
    public function detectPriceFilterCategoryFromValues(array $parameterIds): ?PriceFilterCategory
    {
        $parameterValues = PurchaseParameterValue::with(['group', 'priceFilterCategory'])
            ->whereIn(PurchaseParameterValue::ID, $parameterIds)
            ->get()
            ->keyBy(fn($value) => $value->group->code);

        $customerTypeValue = $parameterValues['customer_type'] ?? null;
        $consultantStatusValue = $parameterValues['consultant_status'] ?? null;
        $purchasePurposeValue = $parameterValues['purchase_purpose'] ?? null;

        if ($customerTypeValue && $consultantStatusValue) {
            if ($consultantStatusValue->code === 'with_consultant') {
                $prefix = strstr($customerTypeValue->code, '_', true) ?: $customerTypeValue->code;
                $category = PriceFilterCategory::where(PriceFilterCategory::CODE, "{$prefix}_with_consultant")->first();
                if ($category) {
                    return $category;
                }
            } else {
                if ($customerTypeValue->priceFilterCategory) {
                    return $customerTypeValue->priceFilterCategory;
                }

                $fallbackCode = "{$customerTypeValue->code}_without_consultant";
                $category = PriceFilterCategory::where(PriceFilterCategory::CODE, $fallbackCode)->first();
                if ($category) {
                    return $category;
                }

                $category = PriceFilterCategory::where(PriceFilterCategory::CODE, $customerTypeValue->code)->first();
                if ($category) {
                    return $category;
                }
            }
        }

        foreach ([$consultantStatusValue, $customerTypeValue, $purchasePurposeValue] as $value) {
            if ($value && $value->priceFilterCategory) {
                return $value->priceFilterCategory;
            }
        }

        return PriceFilterCategory::where(PriceFilterCategory::CODE, 'employer_without_consultant')->first();
    }

    public function detectPriceFilters(Request $request): JsonResponse
    {
        $parameterIds = $request->input('parameters');

        if (!$request->filled('parameters') || !is_array($parameterIds)) {
            $cart = null;
            $userId = Auth::id();
            $sessionId = $request->cookie('cart_session_id');

            if ($userId) {
                $cart = Cart::with('purchaseParameters')->where(Cart::USER_ID, $userId)->first();
            } elseif ($sessionId) {
                $cart = Cart::with('purchaseParameters')->where(Cart::SESSION_ID, $sessionId)->first();
            }

            $parameterIds = $cart?->purchaseParameters->pluck(PurchaseParameterValue::ID)->all() ?? [];
        }

        $category = $this->detectPriceFilterCategoryFromValues($parameterIds)
            ?? PriceFilterCategory::where(PriceFilterCategory::CODE, 'employer_without_consultant')->first();

        if (!$category) {
            return error('global.notFound', [], 404);
        }

        return success([], 'global.received_successfully');
    }
}
