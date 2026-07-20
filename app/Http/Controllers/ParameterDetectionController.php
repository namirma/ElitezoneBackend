<?php

namespace App\Http\Controllers;

use App\Models\Cart;
use App\Models\PriceFilterCategory;
use App\Models\PurchaseParameterValue;
use App\Services\Cart\PriceFilterCategoryDetectorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ParameterDetectionController extends Controller
{
    public function __construct(
        private readonly PriceFilterCategoryDetectorService $detector,
    ) {}

    /**
     * Kept for backward compatibility — delegates to the service.
     * New code should inject PriceFilterCategoryDetectorService directly.
     */
    public function detectPriceFilterCategoryFromValues(array $parameterIds): ?PriceFilterCategory
    {
        return $this->detector->detectFromParameterIds($parameterIds);
    }

    public function detectPriceFilters(Request $request): JsonResponse
    {
        $parameterIds = $request->input('parameters');

        if (! $request->filled('parameters') || ! is_array($parameterIds)) {
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

        $category = $this->detector->detectFromParameterIds($parameterIds)
            ?? PriceFilterCategory::where(PriceFilterCategory::CODE, 'employer_without_elite_yar')->first();

        if (! $category) {
            return error('global.notFound', [], 404);
        }

        return success([], 'global.received_successfully');
    }
}
