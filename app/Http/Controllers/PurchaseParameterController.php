<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Requests\SavePurchaseParametersRequest;
use App\Http\Resources\PurchaseParameterValueResource;
use App\Http\Resources\PurchaseParameterGroupResource;
use App\Http\Controllers\ParameterDetectionController;
use App\Models\Cart;
use App\Models\CustomerType;
use App\Models\PriceFilterCategory;
use App\Models\PurchaseParameterGroup;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class PurchaseParameterController extends Controller
{
    /**
     * POST /api/purchase-parameters/user
     */
    public function store(SavePurchaseParametersRequest $request): JsonResponse
    {
        $cart = $this->resolveCart($request);
        $ids  = $request->validated('parameters');

        $cart->purchaseParameters()->sync($ids);

        $priceCat = app(ParameterDetectionController::class)
            ->detectPriceFilterCategoryFromValues($ids)
            ?? PriceFilterCategory::where('code', 'employer_without_consultant')->first();

        if ($priceCat) {
            $cart->priceFilterCategories()->sync([$priceCat->id]);
            $ct = CustomerType::where('code', $priceCat->code)->first();
            if ($ct) {
                $cart->update(['customer_type_id' => $ct->id]);
            }
        } else {
            $cart->priceFilterCategories()->detach();
        }
        
        $values = $cart->purchaseParameters()->with('group')->get();

        return success(
            PurchaseParameterValueResource::collection($values),
            'global.received_successfully'
        )->cookie('cart_session_id', $cart->session_id, 60 * 24 * 30);
    }

    /**
     * GET /api/purchase-parameters/user
     */
    public function userParameters(Request $request): JsonResponse
    {
        $cart   = $this->resolveCart($request);
        $values = $cart->purchaseParameters()->with('group')->get();

        return success(
            PurchaseParameterValueResource::collection($values)
        );
    }

    /**
     * GET /api/purchase-parameters/groups
     */
    public function groups(): JsonResponse
    {
        $groups = PurchaseParameterGroup::active()
            ->ordered()
            ->with(['values' => fn($q) => $q->active()->ordered()])
            ->get();

        return success(
            PurchaseParameterGroupResource::collection($groups)
        );
    }

    /**
     * Resolve or create a cart for current user or guest
     */
    protected function resolveCart(Request $request): Cart
    {
        if (Auth::check()) {
            $cart = Cart::firstOrCreate(['user_id' => Auth::id()]);
        } else {
            $sessionId = $request->cookie('cart_session_id', session()->getId());
            $cart = Cart::firstOrCreate(
                ['session_id' => $sessionId],
                ['user_id' => null]
            );
            cookie()->queue('cart_session_id', $sessionId, 60 * 24 * 30);
        }

        if (! $cart->customer_type_id) {
            $default = CustomerType::where('code', 'employer_without_consultant')->first();
            $cart->update(['customer_type_id' => $default->id]);
        }

        return $cart;
    }
}
