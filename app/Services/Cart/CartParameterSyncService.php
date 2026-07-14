<?php

namespace App\Services\Cart;

use App\Models\Cart;
use App\Models\CustomerType;
use App\Models\PriceFilterCategory;
use App\Models\PurchaseParameterValue;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * Resolves the Cart for the current request and synchronises its
 * PriceFilterCategory + CustomerType from the user's selected purchase
 * parameters.
 *
 * This is distinct from CartResolver, which syncs based on the user's
 * role/badge. Here the driving signal is the purchase-parameter selection
 * (customer_type, elite_yar_status, purchase_purpose).
 */
class CartParameterSyncService
{
    public function __construct(
        private readonly PriceFilterCategoryDetectorService $detector,
    ) {}

    /**
     * Create or load the cart, then sync its price category and customer type
     * from its current purchase parameter selection.
     */
    public function resolve(Request $request): Cart
    {
        $cart = $this->loadOrCreateCart($request);
        $this->syncPriceCategoryAndCustomerType($cart);

        return $cart->loadMissing(['customerType', 'badge', 'priceFilterCategories']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Internals
    // ─────────────────────────────────────────────────────────────────────────

    private function loadOrCreateCart(Request $request): Cart
    {
        if (Auth::id()) {
            return Cart::firstOrCreate([Cart::USER_ID => Auth::id()]);
        }

        $sessionId = $request->cookie('cart_session_id', (string) Str::uuid());
        $cart = Cart::firstOrCreate(
            [Cart::SESSION_ID => $sessionId],
            [Cart::USER_ID    => null],
        );
        cookie()->queue('cart_session_id', $sessionId, 60 * 24 * 30);

        return $cart;
    }

    private function syncPriceCategoryAndCustomerType(Cart $cart): void
    {
        $paramTable        = (new PurchaseParameterValue)->getTable();
        $currentParamIds   = $cart->purchaseParameters()
            ->pluck($paramTable.'.'.PurchaseParameterValue::ID)
            ->all();

        $priceCategory = $this->detector->detectFromParameterIds($currentParamIds)
            ?? PriceFilterCategory::where(PriceFilterCategory::CODE, 'employer_without_elite_yar')->first();

        if ($priceCategory) {
            $cart->priceFilterCategories()->sync([$priceCategory->id]);

            $customerType = CustomerType::where(CustomerType::CODE, $priceCategory->code)->first();
            if ($customerType && $cart->{Cart::CUSTOMER_TYPE_ID} !== $customerType->id) {
                $cart->update([Cart::CUSTOMER_TYPE_ID => $customerType->id]);
            }
        } elseif (! $cart->{Cart::CUSTOMER_TYPE_ID}) {
            $defaultType = CustomerType::where(CustomerType::CODE, 'employer_without_elite_yar')->firstOrFail();
            $cart->update([Cart::CUSTOMER_TYPE_ID => $defaultType->id]);
        }
    }
}
