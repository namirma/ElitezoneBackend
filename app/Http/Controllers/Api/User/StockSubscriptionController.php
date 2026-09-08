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

        // ⚠️ سه حالت، نه دو تا. `index()` فقط اشتراک‌های `notified_at = null` را
        // نشان می‌دهد (اشتراکِ انجام‌شده دیگر «در انتظار» نیست)، ولی این متد
        // ردیف را بدونِ آن فیلتر پیدا می‌کرد. یعنی بعد از یک‌بار اعلان، کاربر
        // در فهرست «مشترک نیستم» می‌دید و با زدنِ دکمه — به قصدِ اشتراک —
        // ردیف **حذف** می‌شد و پاسخ «اشتراک لغو شد» برمی‌گشت: دکمه وارونه.
        //
        // ساختنِ ردیفِ دوم هم ممکن نیست؛ `unique(user_id, product_variant_id)`
        // آن را رد می‌کند. پس اشتراکِ انجام‌شده باید دوباره **مسلح** شود.
        // larastan 3 نتیجهٔ رابطه را `Model` می‌بیند، نه `StockSubscription`.
        /** @var StockSubscription|null $subscription */
        if ($subscription === null) {
            $user->stockSubscriptions()->create([
                StockSubscription::PRODUCT_VARIANT_ID => $variantId,
            ]);
            $isSubscribed = true;
            $message = __('stock.subscribed');
        } elseif ($subscription->{StockSubscription::NOTIFIED_AT} !== null) {
            $subscription->update([StockSubscription::NOTIFIED_AT => null]);
            $isSubscribed = true;
            $message = __('stock.subscribed');
        } else {
            $subscription->delete();
            $isSubscribed = false;
            $message = __('stock.unsubscribed');
        }

        return success(['is_subscribed' => $isSubscribed], $message);
    }
}
