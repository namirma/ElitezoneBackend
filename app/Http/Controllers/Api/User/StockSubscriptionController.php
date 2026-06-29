<?php

namespace App\Http\Controllers\Api\User;

use App\Http\Controllers\Controller;
use App\Http\Requests\Product\ToggleStockSubscriptionRequest;
use App\Http\Resources\Product\SubscribedVariantResource;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StockSubscription;
use App\Services\Cart\CartResolver;
use App\Services\Pricing\VariantPriceHydrator;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

class StockSubscriptionController extends Controller
{
    public function __construct(
        private readonly CartResolver $cartResolver,
        private readonly VariantPriceHydrator $priceHydrator,
    ) {}

    public function index(): JsonResponse
    {
        $user = Auth::user();
        $variants = ProductVariant::whereHas('stockSubscriptions', function ($query) use ($user) {
            $query->where(StockSubscription::USER_ID, $user->id)
                ->whereNull(StockSubscription::NOTIFIED_AT);
        })
            ->with([
                'product:'.Product::ID.','.Product::NAME.','.Product::SLUG,
                'variantAttributes.attributeType',
                'prices.factory',
                'prices.factoryLocation',
            ])
            ->paginate($this->perPage(10));

        $this->priceHydrator->hydrateMany($variants->getCollection(), $this->cartResolver->activeCode());

        return success(
            SubscribedVariantResource::collection($variants)->response()->getData(true),
            __('stock.list_success')
        );
    }

    public function toggle(ToggleStockSubscriptionRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $variantId = $validated['variant_id'];
        $user = Auth::user();

        $subscription = $user->stockSubscriptions()
            ->where(StockSubscription::PRODUCT_VARIANT_ID, $variantId)
            ->first();

        if ($subscription) {
            $subscription->delete();
            $isSubscribed = false;
            $message = __('stock.unsubscribed');
        } else {
            $user->stockSubscriptions()->create([
                StockSubscription::PRODUCT_VARIANT_ID => $variantId,
            ]);
            $isSubscribed = true;
            $message = __('stock.subscribed');
        }

        return success(['is_subscribed' => $isSubscribed], $message);
    }
}
