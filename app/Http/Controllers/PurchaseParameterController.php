<?php

namespace App\Http\Controllers;

use App\Http\Requests\Cart\SavePurchaseParametersRequest;
use App\Http\Resources\Cart\PurchaseParameterGroupResource;
use App\Http\Resources\Cart\PurchaseParameterValueResource;
use App\Models\PurchaseParameterGroup;
use App\Models\PurchaseParameterValue;
use App\Services\Cart\CartParameterSyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PurchaseParameterController extends Controller
{
    public function __construct(
        private readonly CartParameterSyncService $cartSync,
    ) {}

    public function store(SavePurchaseParametersRequest $request): JsonResponse
    {
        $cart = $this->cartSync->resolve($request);
        $cart->purchaseParameters()->sync($request->validated('parameters'));

        // Re-resolve so the synced parameters drive the price-category update.
        $cart = $this->cartSync->resolve($request);

        return success([], 'global.received_successfully')
            ->cookie('cart_session_id', $cart->session_id, 60 * 24 * 30);
    }

    public function userParameters(Request $request): JsonResponse
    {
        $cart = $this->cartSync->resolve($request);
        $explicitlySetValues = $cart->purchaseParameters()->with('group')->get();

        if ($explicitlySetValues->isNotEmpty()) {
            return success(
                PurchaseParameterValueResource::collection($explicitlySetValues),
                'global.received_successfully'
            );
        }

        $defaultParameterValueCodesByGroup = [
            'customer_type' => 'employer',
            'elite_yar_status' => 'without_elite_yar',
            'purchase_purpose' => 'for_myself',
        ];

        $defaultValuesToReturn = collect();

        foreach ($defaultParameterValueCodesByGroup as $groupCode => $valueCode) {
            $paramValue = PurchaseParameterValue::with('group')
                ->where(PurchaseParameterValue::CODE, $valueCode)
                ->whereHas('group', fn ($q) => $q->where(PurchaseParameterGroup::CODE, $groupCode))
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
