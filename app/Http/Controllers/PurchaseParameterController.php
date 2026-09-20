<?php

namespace App\Http\Controllers;

use App\Http\Requests\Cart\SavePurchaseParametersRequest;
use App\Http\Resources\Cart\PurchaseParameterGroupResource;
use App\Http\Resources\Cart\PurchaseParameterValueResource;
use App\Models\PurchaseParameterGroup;
use App\Models\PurchaseParameterValue;
use App\Services\Cart\CartParameterSyncService;
use App\Services\Cart\CartResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PurchaseParameterController extends Controller
{
    public function __construct(
        private readonly CartParameterSyncService $cartSync,
        private readonly CartResolver $carts,
    ) {}

    /**
     * ⚠️ این اندپوینت سبد را **تغییر** می‌دهد، پس قفلِ لینکِ پرداخت را لازم دارد.
     *
     * پارامترهای خرید دسته‌ی فیلترِ قیمت و نوعِ مشتری را عوض می‌کنند (همان
     * `syncPriceCategoryAndCustomerType` در سرویس)، یعنی **قیمت‌ها را**. شش
     * اندپوینتِ سبد از `CartResolver::resolveForMutation()` رد می‌شوند، ولی این
     * یکی سبد را از سرویسِ خودش می‌گیرد و گارد را نمی‌دید: الیت‌یار می‌توانست
     * بعد از پیامکِ لینکِ پرداخت، پارامترها را عوض کند و کارفرما مبلغی غیر از
     * آنچه در پیامک دیده بود بپردازد — همان یافته‌ی ۱-۲ ممیزی که `9ff1b1a`
     * می‌خواست ببندد. همان گاردِ واحد این‌جا هم صدا زده می‌شود، نه یک کپی — و
     * **پیش از** `cartSync->resolve()`، چون خودِ آن هم دسته‌ی قیمت را sync می‌کند.
     */
    public function store(SavePurchaseParametersRequest $request): JsonResponse
    {
        $this->carts->resolveForMutation($request);

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
