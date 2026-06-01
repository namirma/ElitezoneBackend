<?php

namespace App\Services\Cart;

use App\DataTransferObjects\Pricing\CartPricingResult;
use App\DataTransferObjects\Pricing\PricingContextData;
use App\Http\Resources\Cart\CartResource;
use App\Models\Cart;
use App\Models\CartItem;
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
        private readonly CartResolver $resolver,
    ) {}

    /**
     * Build the standard cart response and attach a guest cookie if applicable.
     */
    public function build(Cart $cart): JsonResponse
    {
        $cart->loadMissing(self::DISPLAY_RELATIONS);

        $context = $this->buildPricingContext($cart);
        $result = $this->cartPricing->calculate($cart, $context);

        // Re-price each line to the CURRENT pricing role and persist it back onto the
        // cart_item snapshot. Without this, a line priced as a guest
        // (employer_without_consultant) keeps that frozen price after the user logs in,
        // so the displayed amount no longer matches the role-based per-kg price shown
        // on the product. Re-pricing here keeps the price consistent from the product
        // page through to the order, exactly as PlaceOrderAction recomputes it.
        $this->persistLinePricing($cart, $result);

        $response = success([
            'cart' => new CartResource($cart),
            'totals' => [
                'subtotal' => (string) $result->subtotal,
                'vat_amount' => (string) $result->vatAmount,
                'total_variant_discount' => (string) $result->totalVariantDiscount,
                'cart_level_offer_discount' => (string) $result->offerDiscount,
                'total' => (string) $result->total,
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
        $pfId = PriceFilterCategory::where(PriceFilterCategory::CODE, $activeCode)->value('id');
        $filterIds = $pfId ? [$pfId] : $cart->priceFilterCategories->pluck('id')->all();

        return new PricingContextData(
            activeCode: $activeCode,
            customerType: $cart->customerType,
            badge: $cart->badge,
            filterCategoryIds: $filterIds,
            withVat: true,
            offerBasis: 'weight',
        );
    }

    /**
     * Persist the freshly computed, role-based per-line price back onto each cart item
     * (price / final_price / vat_amount / total_with_vat) so the stored snapshot — and
     * therefore the displayed amount — always reflects the current pricing role.
     *
     * Uses the same money shape as AddToCartAction and PlaceOrderAction: finalPrice is
     * ex-VAT and total_with_vat = baseQuantity × (finalPrice + unitVat). Only writes when
     * the value actually changed (e.g. a guest cart viewed after login) to avoid
     * needless updates.
     */
    private function persistLinePricing(Cart $cart, CartPricingResult $result): void
    {
        $linesById = collect($result->lines)->keyBy(fn ($line) => $line->cartItemId);

        foreach ($cart->items as $item) {
            $line = $linesById->get($item->id);
            if (! $line) {
                continue;
            }

            $lineVat = $line->baseQuantity * $line->unitVatAmount;
            $totalWithVat = $line->baseQuantity * ($line->unitPrice + $line->unitVatAmount);

            $changed = abs((float) $item->final_price - $line->unitPrice) > 0.01
                || abs((float) $item->total_with_vat - $totalWithVat) > 0.01;

            if (! $changed) {
                continue;
            }

            $item->forceFill([
                CartItem::PRICE => $line->unitPrice,
                CartItem::FINAL_PRICE => $line->unitPrice,
                CartItem::VAT_AMOUNT => $lineVat,
                CartItem::TOTAL_WITH_VAT => $totalWithVat,
            ])->save();
        }
    }
}
