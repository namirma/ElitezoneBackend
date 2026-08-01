<?php

namespace App\Services\Pricing;

use App\DataTransferObjects\Pricing\CartLinePricingResult;
use App\DataTransferObjects\Pricing\CartPricingResult;
use App\DataTransferObjects\Pricing\PricingContextData;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Offer;
use App\Models\OrderUnit;
use App\Services\PriceCalculationService;
use Illuminate\Support\Collection;

class CartPricingCalculator
{
    public function __construct(private readonly PriceCalculationService $priceService) {}

    public function calculate(Cart $cart, PricingContextData $ctx): CartPricingResult
    {
        $cart->loadMissing([
            'items.productVariant.product.offers',
            'items.factory',
            'items.factoryLocation',
            'customerType',
            'badge',
            'priceFilterCategories',
        ]);

        $unitsMap = $this->unitsMap();

        $lines = [];
        $subtotalStr = '0.0000';
        $vatAmountStr = '0.0000';
        $totalVariantDiscountStr = '0.0000';

        foreach ($cart->items as $item) {
            $pricing = $this->priceService->calculatePrice(
                variant: $item->productVariant,
                factory: $item->factory,
                location: $item->factoryLocation,
                customerType: $ctx->customerType,
                badge: $ctx->badge,
                filterCategoryIds: $ctx->filterCategoryIds,
                withVAT: $ctx->withVat,
                filterCategoryCode: $ctx->activeCode,
            );

            $quantities = is_string($item->{CartItem::QUANTITIES})
                ? json_decode($item->{CartItem::QUANTITIES}, true)
                : $item->{CartItem::QUANTITIES};

            $baseQty = $this->calculateBaseQuantity($quantities ?? [], $unitsMap);
            $baseQtyStr = (string) $baseQty;

            $unitPriceStr = (string) (float) $pricing->finalPrice;
            $unitVatStr = (string) (float) ($pricing->vatAmount ?? 0.0);
            $discountPerUnitStr = (string) (float) ($pricing->variantDiscountAmount ?? 0.0);

            // finalPrice is already the ex-VAT payable price (see PricingContext::toDto,
            // where priceWithVat = finalPrice + vatAmount). The previous code subtracted
            // VAT from it again, so the line subtotal — and the cart total — came out one
            // VAT step short. The subtotal is baseQty × finalPrice; VAT is added on top.
            $lineSubtotalStr = bcmul($baseQtyStr, $unitPriceStr, 4);
            $lineVatStr = bcmul($baseQtyStr, $unitVatStr, 4);
            $lineTotalStr = bcadd($lineSubtotalStr, $lineVatStr, 4);
            $lineDiscountStr = bcmul($discountPerUnitStr, $baseQtyStr, 4);

            // جمع‌زدن کل فاکتور
            $subtotalStr = bcadd($subtotalStr, $lineSubtotalStr, 4);
            $vatAmountStr = bcadd($vatAmountStr, $lineVatStr, 4);
            $totalVariantDiscountStr = bcadd($totalVariantDiscountStr, $lineDiscountStr, 4);

            $lines[] = new CartLinePricingResult(
                cartItemId: $item->id,
                baseQuantity: $baseQty,
                unitPrice: (float) $unitPriceStr,
                unitVatAmount: (float) $unitVatStr,
                variantDiscountPerUnit: (float) $discountPerUnitStr,
                lineSubtotal: (float) $lineSubtotalStr,
                lineVat: (float) $lineVatStr,
                lineTotal: (float) $lineTotalStr,
                unitPriceDetails: method_exists($pricing, 'toArray') ? $pricing->toArray() : (array) $pricing,
            );
        }

        $subtotalFloat = (float) $subtotalStr;

        // محاسبه تخفیف‌های آفر — درصد باید روی سطرِ واجدِ شرایط بنشیند، نه کلِ سبد،
        // پس زیرمجموعِ هر سطر هم پاس داده می‌شود.
        $lineSubtotals = [];
        foreach ($lines as $line) {
            $lineSubtotals[$line->cartItemId] = (string) $line->lineSubtotal;
        }

        [$offerDiscountFloat, $appliedOffers] = $this->calculateOfferDiscount(
            items: $cart->items,
            subtotal: $subtotalFloat,
            unitsMap: $unitsMap,
            offerBasis: $ctx->offerBasis,
            lineSubtotals: $lineSubtotals,
        );

        $shippingCostStr = '0.0000';

        // محاسبه مجموع نهایی: (subtotal - offerDiscount) + vat + shipping
        $afterOfferStr = bcsub($subtotalStr, (string) $offerDiscountFloat, 4);
        if ((float) $afterOfferStr < 0) {
            $afterOfferStr = '0.0000';
        }

        // Spec ("پروسه خرید"): VAT applies to the POST-discount base — every settlement
        // formula is "…×(1−%OW)×(1+VAT)", i.e. VAT on the already-discounted amount. So a
        // cart offer that reduces the subtotal reduces the VAT proportionally; otherwise
        // the buyer is charged VAT on the pre-offer amount and over-pays.
        $effectiveVatStr = $vatAmountStr;
        if ($offerDiscountFloat > 0 && $subtotalFloat > 0) {
            $vatRatioStr = bcdiv($afterOfferStr, $subtotalStr, 8);   // afterOffer / subtotal
            $effectiveVatStr = bcmul($vatAmountStr, $vatRatioStr, 4);
        }

        $totalStr = bcadd($afterOfferStr, $effectiveVatStr, 4);
        $totalStr = bcadd($totalStr, $shippingCostStr, 4);

        return new CartPricingResult(
            lines: $lines,
            subtotal: $subtotalFloat,
            vatAmount: (float) $effectiveVatStr,
            totalVariantDiscount: (float) $totalVariantDiscountStr,
            offerDiscount: $offerDiscountFloat,
            shippingCost: (float) $shippingCostStr,
            total: (float) $totalStr,
            appliedOffers: $appliedOffers,
        );
    }

    private function unitsMap(): Collection
    {
        return OrderUnit::allCached();
    }

    private function calculateBaseQuantity(array $quantities, Collection $unitsMap): float
    {
        $baseStr = '0.0000';
        foreach ($quantities as $unitId => $qty) {
            $unit = $unitsMap->get((int) $unitId);
            if ($unit && (float) $unit->amount > 0) {
                $addedQty = bcmul((string) $qty, (string) $unit->amount, 4);
                $baseStr = bcadd($baseStr, $addedQty, 4);
            }
        }

        return (float) $baseStr;
    }

    /**
     * تخفیفِ آفر — پشتِ `features.product_offers`، پیش‌فرض خاموش.
     *
     * سه نقص اینجا بود، هر سه اصلاح شد؛ ولی خودِ فلگ گاردِ اصلی است، چون
     * `PlaceOrderAction` تخفیفِ آفر را **اصلاً ثبت نمی‌کند** (ستونش روی `orders`
     * وجود ندارد). پس هر عددی که این متد برگرداند، عددی است که خریدار می‌بیند و
     * نمی‌پردازد. جزئیات در `config/features.php`.
     *
     * @param  array<int,string>  $lineSubtotals  cartItemId → زیرمجموعِ همان سطر
     * @return array{0: float, 1: list<array{id:int,type:string,value:float}>}
     */
    private function calculateOfferDiscount(
        Collection $items,
        float $subtotal,
        Collection $unitsMap,
        string $offerBasis,
        array $lineSubtotals,
    ): array {
        if (! config('features.product_offers')) {
            return [0.0, []];
        }

        $now = now();
        $discountStr = '0.0000';
        $applied = [];
        $seenOffers = [];

        foreach ($items as $item) {
            $quantities = is_string($item->{CartItem::QUANTITIES})
                ? json_decode($item->{CartItem::QUANTITIES}, true)
                : $item->{CartItem::QUANTITIES};

            if (! is_array($quantities)) {
                $quantities = [];
            }

            $thresholdValue = $offerBasis === 'count'
                ? array_sum($quantities)
                : $this->calculateBaseQuantity($quantities, $unitsMap);

            $lineSubtotalStr = $lineSubtotals[$item->id] ?? '0.0000';

            foreach ($item->productVariant->product->offers as $offer) {
                // `is_active` اصلاً چک نمی‌شد. یعنی آفرِ غیرفعالی که
                // `end_date`ش NULL بود (پیش‌فرضِ مایگریشن) تا ابد تخفیف می‌داد و
                // تنها راه توقفش عقب‌بردنِ تاریخ بود. `Offer::scopeCurrent()` از
                // قبل درست چک می‌کرد؛ این مسیر آن را دور می‌زد.
                if (! $offer->{Offer::IS_ACTIVE}) {
                    continue;
                }

                $isCurrent = (! $offer->start_date || $offer->start_date <= $now) &&
                             (! $offer->end_date || $offer->end_date >= $now);

                if (! $isCurrent || $offer->minimum_quantity > $thresholdValue) {
                    continue;
                }

                // یک آفر روی چند سطرِ همان محصول دوباره حساب نمی‌شود.
                $key = $offer->id.':'.$item->id;
                if (isset($seenOffers[$key])) {
                    continue;
                }
                $seenOffers[$key] = true;

                // درصد روی **زیرمجموعِ همان سطر** اعمال می‌شود، نه کلِ سبد.
                // قبلاً `bcmul($subtotal, $rate)` بود: آفرِ ۱۰٪ِ یک محصولِ
                // ۱۰ میلیونی در سبدی یک‌میلیاردی، ۱۰۰ میلیون تخفیف می‌داد — صد
                // برابرِ ارزشِ سطری که آن را کسب کرده بود. چهار آفرِ این‌چنینی
                // جمعاً به ۱۰۰٪ می‌رسید و سبد رایگان می‌شد.
                $dStr = '0.0000';
                if ($offer->type === 'percentage') {
                    $rateStr = bcdiv((string) $offer->value, '100', 4);
                    $dStr = bcmul($lineSubtotalStr, $rateStr, 4);
                } elseif ($offer->type === 'fixed_amount') {
                    // مبلغِ ثابت هم از خودِ سطر بیشتر نمی‌شود.
                    $dStr = bccomp((string) $offer->value, $lineSubtotalStr, 4) === 1
                        ? $lineSubtotalStr
                        : (string) $offer->value;
                }

                $discountStr = bcadd($discountStr, $dStr, 4);

                if (! in_array($offer->id, array_column($applied, 'id'), true)) {
                    $applied[] = [
                        'id' => $offer->id,
                        'type' => $offer->type,
                        'value' => (float) $offer->value,
                    ];
                }
            }
        }

        $finalDiscount = min((float) $discountStr, max(0, $subtotal));

        return [$finalDiscount, $applied];
    }
}
