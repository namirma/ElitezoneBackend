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
        // بارگذاری کامل روابط برای تضمین عدم وقوع N+1
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
        $subtotal = 0.0;
        $vatAmount = 0.0;
        $totalVariantDiscount = 0.0;

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

            // استخراج فیلد quantities (هندل کردن JSON یا آرایه بودن آن)
            $quantities = is_string($item->{CartItem::QUANTITIES})
                ? json_decode($item->{CartItem::QUANTITIES}, true)
                : $item->{CartItem::QUANTITIES};

            $baseQty = $this->calculateBaseQuantity($quantities ?? [], $unitsMap);

            $unitPrice = (float) $pricing->finalPrice;
            $unitVat   = (float) ($pricing->vatAmount ?? 0.0);
            $discountPerUnit = (float) ($pricing->variantDiscountAmount ?? 0.0);

            // محاسبه دقیق و تفکیک شده (بدون تداخل مالیاتی)
            $lineSubtotal = $baseQty * ($unitPrice - $unitVat);
            $lineVat      = $baseQty * $unitVat;
            $lineTotal    = $lineSubtotal + $lineVat;

            $subtotal += $lineSubtotal;
            $vatAmount += $lineVat;
            $totalVariantDiscount += ($discountPerUnit * $baseQty);

            $lines[] = new CartLinePricingResult(
                cartItemId: $item->id,
                baseQuantity: $baseQty,
                unitPrice: $unitPrice,
                unitVatAmount: $unitVat,
                variantDiscountPerUnit: $discountPerUnit,
                lineSubtotal: $lineSubtotal,
                lineVat: $lineVat,
                lineTotal: $lineTotal,
                unitPriceDetails: method_exists($pricing, 'toArray') ? $pricing->toArray() : (array) $pricing,
            );
        }

        // محاسبه تخفیف‌های آفر
        [$offerDiscount, $appliedOffers] = $this->calculateOfferDiscount(
            items: $cart->items,
            subtotal: $subtotal,
            unitsMap: $unitsMap,
            offerBasis: $ctx->offerBasis
        );

        $shippingCost = 0.0;

        // جمع کل سبد خرید
        $total = max(0, ($subtotal - $offerDiscount) + $vatAmount + $shippingCost);

        return new CartPricingResult(
            lines: $lines,
            subtotal: $subtotal,
            vatAmount: $vatAmount,
            totalVariantDiscount: $totalVariantDiscount,
            offerDiscount: $offerDiscount,
            shippingCost: $shippingCost,
            total: $total,
            appliedOffers: $appliedOffers,
        );
    }

    private function unitsMap(): Collection
    {
        return Cache::remember('order_units_map', 86400, fn() => OrderUnit::all()->keyBy('id'));
    }

    private function calculateBaseQuantity(array $quantities, Collection $unitsMap): float
    {
        $base = 0.0;
        foreach ($quantities as $unitId => $qty) {
            $unit = $unitsMap->get((int)$unitId);
            if ($unit && (float)$unit->amount > 0) {
                $base += (float)$qty * (float)$unit->amount;
            }
        }
        return $base;
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

        $discount = 0.0;
        $applied = [];
        
        foreach ($offers as $offer) {
            $d = 0.0;
            if ($offer->type === 'percentage') {
                $d = $subtotal * ((float)$offer->value / 100);
            } elseif ($offer->type === 'fixed_amount') {
                $d = (float)$offer->value;
            }
            $discount += $d;

            $applied[] = [
                'id' => $offer->id,
                'type' => $offer->type,
                'value' => (float)$offer->value,
            ];
        }

        $discount = min($discount, max(0, $subtotal));
        return [$discount, $applied];
    }
}
