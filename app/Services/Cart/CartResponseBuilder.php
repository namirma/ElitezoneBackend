<?php

namespace App\Services\Cart;

use App\DataTransferObjects\Pricing\CartPricingResult;
use App\DataTransferObjects\Pricing\PricingContextData;
use App\Http\Resources\Cart\CartResource;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\PriceFilterCategory;
use App\Services\Pricing\CartPricingCalculator;
use App\Services\Pricing\VariantPriceHydrator;
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
        // حدود سفارشِ واریانت بر حسب «واحد محدودیت» سنجیده می‌شوند
        // (`checkVariantAggregateLimits`). بدون این لود، `limit_order_unit` در پاسخ
        // نیست و ویرایشگرِ سبد نمی‌تواند حداقل/حداکثر را نشان دهد — قبلاً این اعداد در
        // فرانت هاردکد بودند و این گپ دیده نمی‌شد.
        'items.productVariant.limitOrderUnit',
        // Prices + their sub-relations must be eager-loaded so CartItemResource →
        // ProductVariantResource can render role-based final_price / role_* fields
        // without N+1 queries and without falling back to the raw DB special_price.
        'items.productVariant.prices.factory',
        'items.productVariant.prices.factoryLocation',
        'items.factory',
        'items.factory.activeShippingFeatures',
        'items.factoryLocation',
        'items.shippingMethod',
        'customerType',
        'badge',
        'priceFilterCategories',
    ];

    public function __construct(
        private readonly CartPricingCalculator $cartPricing,
        private readonly CartResolver $resolver,
        private readonly VariantPriceHydrator $priceHydrator,
        private readonly CartPriceValidityResolver $priceValidity,
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
        // (employer_without_elite_yar) keeps that frozen price after the user logs in,
        // so the displayed amount no longer matches the role-based per-kg price shown
        // on the product. Re-pricing here keeps the price consistent from the product
        // page through to the order, exactly as PlaceOrderAction recomputes it.
        $this->persistLinePricing($cart, $result);

        // Hydrate role-based pricing onto each item's product-variant price rows so that
        // CartItemResource → ProductVariantResource renders the same final_price / role_*
        // figures as the product-listing endpoint (FilterProductVariantsAction).
        // Without this, ProductVariantResource falls back to the raw DB special_price,
        // causing the frontend to display a different price/discount-badge on the cart
        // page than on the product page.
        $this->hydrateItemVariantPrices($cart, $context->activeCode);

        $response = success([
            'cart' => new CartResource($cart),
            'totals' => [
                'subtotal' => (string) $result->subtotal,
                'vat_amount' => (string) $result->vatAmount,
                'total_variant_discount' => (string) $result->totalVariantDiscount,
                'cart_level_offer_discount' => (string) $result->offerDiscount,
                'total' => (string) $result->total,
            ],
            // مهلتِ اعتبارِ قیمت/موجودی — `null` یعنی مهلتی اعلام نشده (نه «منقضی»).
            // منبعِ نهایی API تأمین‌کننده است؛ فعلاً از CartPriceValidityResolver.
            'price_valid_until' => $this->priceValidity->resolveAsIso($cart),
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
     * Run the pricing pipeline on the product-variant price rows that are attached to
     * each cart item, so that CartItemResource → ProductVariantResource serialises the
     * same role-based final_price / role_* / min_price as the product-listing endpoint.
     *
     * Variants are deduplicated so a variant referenced by multiple cart items is only
     * hydrated once.
     */
    private function hydrateItemVariantPrices(Cart $cart, string $activeCode): void
    {
        $variants = $cart->items
            ->map(fn ($item) => $item->productVariant)
            ->filter()
            ->unique('id')
            ->values();

        $this->priceHydrator->hydrateMany($variants, $activeCode);
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
