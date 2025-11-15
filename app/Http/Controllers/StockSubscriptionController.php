<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\ProductVariant;
use App\Http\Resources\SubscribedVariantResource;
use App\Http\Requests\ToggleStockSubscriptionRequest;
use App\Models\Product;
use App\Models\StockSubscription;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

class StockSubscriptionController extends Controller
{
    public function index(): JsonResponse
    {
        $user = Auth::user();
        $variants = ProductVariant::whereHas('stockSubscriptions', function ($query) use ($user) {
            // 🚀 استفاده از ثابت‌ها
            $query->where(StockSubscription::USER_ID, $user->id)
                ->whereNull(StockSubscription::NOTIFIED_AT);
        })
            ->with([
                'product:' . Product::ID . ',' . Product::NAME . ',' . Product::SLUG,
                'variantAttributes.attributeType'
            ])
            ->paginate(10);

        // 🚀 استفاده از هلپر success و خواندن پیام از lang
        return success(
            SubscribedVariantResource::collection($variants)->response()->getData(true),
            __('auth.stock.list_success')
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
            $message = __('auth.stock.unsubscribed');
        } else {
            $user->stockSubscriptions()->create([
                StockSubscription::PRODUCT_VARIANT_ID => $variantId
            ]);
            $isSubscribed = true;
            $message = __('auth.stock.subscribed');
        }

        return success(['is_subscribed' => $isSubscribed], $message);
    }
}
