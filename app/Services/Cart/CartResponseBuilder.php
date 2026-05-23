<?php

namespace App\Services\Cart;

use App\DataTransferObjects\Pricing\PricingContextData;
use App\Http\Resources\Cart\CartResource;
use App\Models\Cart;
use App\Models\PriceFilterCategory;
use App\Services\Pricing\CartPricingCalculator;
use Illuminate\Http\JsonResponse;

/**
 * Loads the cart's display-time relations, runs the pricing pipeline, and
 * builds the standard `{ cart, totals }` JSON response.
 *
 * Centralises what every CartController endpoint did manually so the
 * controller becomes a thin orchestrator.
 */
class CartResponseBuilder
{
    /** Relations needed by CartResource — eager-loaded once before pricing. */
    private const DISPLAY_RELATIONS = [
        'items.productVariant.product.images',
        'items.productVariant.variantAttributes.attributeType',
        'items.productVariant.product.orderUnits',
        'items.factory',
        'items.factoryLocation',
        'items.shippingMethod',
        'customerType',
        'badge',
        'priceFilterCategories',
    ];

    public function __construct(
        private readonly CartPricingCalculator $cartPricing,
        private readonly CartResolver          $resolver,
    ) {}

    /**
     * Build the standard cart response and attach a guest cookie if applicable.
     */
    public function build(Cart $cart): JsonResponse
    {
        $cart->loadMissing(self::DISPLAY_RELATIONS);

        $context = $this->buildPricingContext($cart);
        $result  = $this->cartPricing->calculate($cart, $context);

        $this->attachPricingResultsToItems($cart, $result);

        $response = success([
            'cart'   => new CartResource($cart),
            'totals' => [
                'subtotal'                  => (string) $result->subtotal,
                'vat_amount'                => (string) $result->vatAmount,
                'total_variant_discount'    => (string) $result->totalVariantDiscount,
                'cart_level_offer_discount' => (string) $result->offerDiscount,
                'total'                     => (string) $result->total,
            ],
        ], 'global.received_successfully');

        if (! $cart->user_id) {
            $response->cookie('cart_session_id', $cart->session_id, 60 * 24 * 30);
        }

        return $response;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Internals
    // ─────────────────────────────────────────────────────────────────────────

    private function buildPricingContext(Cart $cart): PricingContextData
    {
        $activeCode = $this->resolver->activeCode();

        // اگر فیلتر خاصی برای نقش فعال اجباری است آن را بگیر، وگرنه فیلترهای انتخابی کاربر
        $pfId      = PriceFilterCategory::where(PriceFilterCategory::CODE, $activeCode)->value('id');
        $filterIds = $pfId ? [$pfId] : $cart->priceFilterCategories->pluck('id')->all();

        return new PricingContextData(
            activeCode:        $activeCode,
            customerType:      $cart->customerType,
            badge:             $cart->badge,
            filterCategoryIds: $filterIds,
            withVat:           true,
            offerBasis:        'weight',
        );
    }

    /** Inject per-line pricing results into CartItem instances for the Resource. */
    private function attachPricingResultsToItems(Cart $cart, $result): void
    {
        if (! isset($result->items)) {
            return;
        }

        foreach ($cart->items as $item) {
            if ($lineResult = $result->items->get($item->id)) {
                $item->setRelation('pricing_result', $lineResult);
            }
        }
    }
}
