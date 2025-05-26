<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Requests\SavePurchaseParametersRequest;
use App\Http\Resources\PurchaseParameterGroupResource;
use App\Http\Resources\PurchaseParameterValueResource;
use App\Models\Cart;
use App\Models\CustomerType;
use App\Models\PriceFilterCategory;
use App\Models\PurchaseParameterGroup;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class PurchaseParameterController extends Controller
{
    public function store(SavePurchaseParametersRequest $request): JsonResponse
    {
        $cart = $this->resolveCart($request);
        $parameterIds = $request->validated('parameters');

        $cart->purchaseParameters()->sync($parameterIds);

        $priceCategory = app(ParameterDetectionController::class)
            ->detectPriceFilterCategoryFromValues($parameterIds)
            ?? PriceFilterCategory::where(PriceFilterCategory::CODE, 'employer_without_consultant')->first();

        if ($priceCategory) {
            $cart->priceFilterCategories()->sync([$priceCategory->id]);
            $customerType = CustomerType::where(CustomerType::CODE, $priceCategory->code)->first();
            if ($customerType) {
                $cart->update([Cart::CUSTOMER_TYPE_ID => $customerType->id]);
            }
        } else {
            $cart->priceFilterCategories()->detach();
            $defaultCustomerType = CustomerType::where(CustomerType::CODE, 'employer_without_consultant')->firstOrFail();
            $cart->update([Cart::CUSTOMER_TYPE_ID => $defaultCustomerType->id]);
        }

        $values = $cart->purchaseParameters()->with('group')->get();

        return success(
            PurchaseParameterValueResource::collection($values),
            'global.received_successfully'
        )->cookie('cart_session_id', $cart->session_id, 60 * 24 * 30);
    }

    public function userParameters(Request $request): JsonResponse
    {
        $cart = $this->resolveCart($request);
        $values = $cart->purchaseParameters()->with('group')->get();

        return success(
            PurchaseParameterValueResource::collection($values),
            'global.received_successfully'
        );
    }

    public function groups(): JsonResponse
    {
        $groups = PurchaseParameterGroup::query()
            ->active()
            ->ordered()
            ->with(['values' => fn($query) => $query->active()->ordered()])
            ->get();

        return success(
            PurchaseParameterGroupResource::collection($groups),
            'global.received_successfully'
        );
    }

    protected function resolveCart(Request $request): Cart
    {
        $userId = Auth::id();

        if ($userId) {
            $cart = Cart::firstOrCreate([Cart::USER_ID => $userId]);
        } else {
            $sessionId = $request->cookie('cart_session_id', (string) Str::uuid());
            $cart = Cart::firstOrCreate(
                [Cart::SESSION_ID => $sessionId],
                [Cart::USER_ID    => null]
            );
             cookie()->queue('cart_session_id', $sessionId, 60 * 24 * 30);
        }

        if (!$cart->{Cart::CUSTOMER_TYPE_ID}) {
            $defaultCustomerType = CustomerType::where(CustomerType::CODE, 'employer_without_consultant')->firstOrFail();
            $cart->update([Cart::CUSTOMER_TYPE_ID => $defaultCustomerType->id]);
        }

        return $cart;
    }
}
