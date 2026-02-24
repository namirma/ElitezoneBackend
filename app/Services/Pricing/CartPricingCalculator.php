<?php

namespace App\Services\Pricing;

use App\DataTransferObjects\Pricing\CartLinePricingResult;
use App\DataTransferObjects\Pricing\CartPricingResult;
use App\DataTransferObjects\Pricing\PricingContextData;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\OrderUnit;
use App\Services\PriceCalculationService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

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
            $unitVatStr   = (string) (float) ($pricing->vatAmount ?? 0.0);
            $discountPerUnitStr = (string) (float) ($pricing->variantDiscountAmount ?? 0.0);

            $priceWithoutVatStr = bcsub($unitPriceStr, $unitVatStr, 4);
            $lineSubtotalStr    = bcmul($baseQtyStr, $priceWithoutVatStr, 4);
            $lineVatStr         = bcmul($baseQtyStr, $unitVatStr, 4);
            $lineTotalStr       = bcadd($lineSubtotalStr, $lineVatStr, 4);
            $lineDiscountStr    = bcmul($discountPerUnitStr, $baseQtyStr, 4);

            // جمع‌زدن کل فاکتور
            $subtotalStr             = bcadd($subtotalStr, $lineSubtotalStr, 4);
            $vatAmountStr            = bcadd($vatAmountStr, $lineVatStr, 4);
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

        // محاسبه تخفیف‌های آفر
        [$offerDiscountFloat, $appliedOffers] = $this->calculateOfferDiscount(
            items: $cart->items,
            subtotal: $subtotalFloat,
            unitsMap: $unitsMap,
            offerBasis: $ctx->offerBasis
        );

        $shippingCostStr = '0.0000';

        // محاسبه مجموع نهایی: (subtotal - offerDiscount) + vat + shipping
        $afterOfferStr = bcsub($subtotalStr, (string) $offerDiscountFloat, 4);
        if ((float)$afterOfferStr < 0) $afterOfferStr = '0.0000';

        $totalStr = bcadd($afterOfferStr, $vatAmountStr, 4);
        $totalStr = bcadd($totalStr, $shippingCostStr, 4);

        return new CartPricingResult(
            lines: $lines,
            subtotal: $subtotalFloat,
            vatAmount: (float) $vatAmountStr,
            totalVariantDiscount: (float) $totalVariantDiscountStr,
            offerDiscount: $offerDiscountFloat,
            shippingCost: (float) $shippingCostStr,
            total: (float) $totalStr,
            appliedOffers: $appliedOffers,
        );
    }

    private function unitsMap(): Collection
    {
        return Cache::remember('order_units_map', 86400, fn() => OrderUnit::all()->keyBy('id'));
    }

    private function calculateBaseQuantity(array $quantities, Collection $unitsMap): float
    {
        $baseStr = '0.0000';
        foreach ($quantities as $unitId => $qty) {
            $unit = $unitsMap->get((int)$unitId);
            if ($unit && (float)$unit->amount > 0) {
                $addedQty = bcmul((string)$qty, (string)$unit->amount, 4);
                $baseStr = bcadd($baseStr, $addedQty, 4);
            }
        }
        return (float) $baseStr;
    }

    private function calculateOfferDiscount(Collection $items, float $subtotal, Collection $unitsMap, string $offerBasis): array
    {
        $now = now();

        $offers = $items->flatMap(function (CartItem $item) use ($unitsMap, $offerBasis, $now) {
            $quantities = is_string($item->{CartItem::QUANTITIES})
                ? json_decode($item->{CartItem::QUANTITIES}, true)
                : $item->{CartItem::QUANTITIES};

            if (!is_array($quantities)) $quantities = [];

            $thresholdValue = $offerBasis === 'count'
                ? array_sum($quantities)
                : $this->calculateBaseQuantity($quantities, $unitsMap);

            return $item->productVariant->product->offers->filter(function ($offer) use ($thresholdValue, $now) {
                $isCurrent = (!$offer->start_date || $offer->start_date <= $now) &&
                             (!$offer->end_date || $offer->end_date >= $now);
                return $isCurrent && $offer->minimum_quantity <= $thresholdValue;
            });
        })->unique('id')->values();

        $discountStr = '0.0000';
        $applied = [];

        foreach ($offers as $offer) {
            $dStr = '0.0000';
            if ($offer->type === 'percentage') {
                $rateStr = bcdiv((string)$offer->value, '100', 4);
                $dStr = bcmul((string)$subtotal, $rateStr, 4);
            } elseif ($offer->type === 'fixed_amount') {
                $dStr = (string)$offer->value;
            }
            $discountStr = bcadd($discountStr, $dStr, 4);

            $applied[] = [
                'id' => $offer->id,
                'type' => $offer->type,
                'value' => (float)$offer->value,
            ];
        }

        $finalDiscount = min((float)$discountStr, max(0, $subtotal));
        return [$finalDiscount, $applied];
    }
}
