<?php

namespace App\Http\Controllers;

use App\Http\Requests\Cart\SavePurchaseParametersRequest;
use App\Http\Resources\Cart\PurchaseParameterGroupResource;
use App\Http\Resources\Cart\PurchaseParameterValueResource;
use App\Models\Cart;
use App\Models\CustomerType;
use App\Models\PriceFilterCategory;
use App\Models\PurchaseParameterGroup;
use App\Models\PurchaseParameterValue;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class PurchaseParameterController extends Controller
{
    protected function resolveCart(Request $request): Cart
    {
        $userId = Auth::id();
        $cart = null;

        if ($userId) {
            $cart = Cart::firstOrCreate([Cart::USER_ID => $userId]);
        } else {
            $sessionId = $request->cookie('cart_session_id', (string) Str::uuid());
            $cart = Cart::firstOrCreate(
                [Cart::SESSION_ID => $sessionId],
                [Cart::USER_ID => null]
            );
            cookie()->queue('cart_session_id', $sessionId, 60 * 24 * 30);
        }
        $tableName = (new PurchaseParameterValue)->getTable();
        $currentParameterIds = $cart->purchaseParameters()
            ->pluck($tableName.'.'.PurchaseParameterValue::ID)
            ->all();

        $priceCategory = app(ParameterDetectionController::class)
            ->detectPriceFilterCategoryFromValues($currentParameterIds)
            ?? PriceFilterCategory::where(PriceFilterCategory::CODE, 'employer_without_consultant')->first();

        if ($priceCategory) {
            if (! $cart->relationLoaded('priceFilterCategories') || $cart->priceFilterCategories->pluck(PriceFilterCategory::ID)->sort()->values()->all() !== [$priceCategory->id]) {
                $cart->priceFilterCategories()->sync([$priceCategory->id]);
            }

            $customerTypeModel = CustomerType::where(CustomerType::CODE, $priceCategory->code)->first();
            if ($customerTypeModel) {
                if ($cart->{Cart::CUSTOMER_TYPE_ID} !== $customerTypeModel->id) {
                    $cart->update([Cart::CUSTOMER_TYPE_ID => $customerTypeModel->id]);
                }
            }
        } elseif (! $cart->{Cart::CUSTOMER_TYPE_ID}) {
            $defaultCustomerType = CustomerType::where(CustomerType::CODE, 'employer_without_consultant')->firstOrFail();
            $cart->update([Cart::CUSTOMER_TYPE_ID => $defaultCustomerType->id]);
        }

        return $cart->loadMissing(['customerType', 'badge', 'priceFilterCategories']);
    }

    public function store(SavePurchaseParametersRequest $request): JsonResponse
    {
        $cart = $this->resolveCart($request);
        $parameterIds = $request->validated('parameters');
        $cart->purchaseParameters()->sync($parameterIds);

        $cart = $this->resolveCart($request);

        return success(
            [],
            'global.received_successfully'
        )->cookie('cart_session_id', $cart->session_id, 60 * 24 * 30);
    }

    public function userParameters(Request $request): JsonResponse
    {
        $cart = $this->resolveCart($request);
        $explicitlySetValues = $cart->purchaseParameters()->with('group')->get();

        if ($explicitlySetValues->isNotEmpty()) {
            return success(
                PurchaseParameterValueResource::collection($explicitlySetValues),
                'global.received_successfully'
            );
        }

        $defaultParameterValueCodesByGroup = [
            'customer_type' => 'employer',
            'consultant_status' => 'without_consultant',
            'purchase_purpose' => 'for_myself',
        ];

        $defaultValuesToReturn = collect();

        foreach ($defaultParameterValueCodesByGroup as $groupCode => $valueCode) {
            $paramValue = PurchaseParameterValue::with('group')
                ->where(PurchaseParameterValue::CODE, $valueCode)
                ->whereHas('group', function ($query) use ($groupCode) {
                    $query->where(PurchaseParameterGroup::CODE, $groupCode);
                })
                ->first();

            if ($paramValue) {
                $defaultValuesToReturn->push($paramValue);
            }
        }

        return success(
            PurchaseParameterValueResource::collection($defaultValuesToReturn),
            'global.received_successfully'
        );
    }

    public function groups(): JsonResponse
    {
        $groups = PurchaseParameterGroup::query()
            ->active()
            ->ordered()
            ->with(['values' => fn ($query) => $query->active()->ordered()])
            ->get();

        return success(
            PurchaseParameterGroupResource::collection($groups),
            'global.received_successfully'
        );
    }
}
