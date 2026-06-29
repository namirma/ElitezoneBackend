<?php

namespace App\Actions\Order;

use App\Models\Badge;
use App\Models\CustomerType;
use App\Models\Factory;
use App\Models\FactoryLocation;
use App\Models\OrderUnit;
use App\Models\ProductVariant;
use App\Services\PriceCalculationService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class CalculateOrderEstimateAction
{
    public function __construct(
        private readonly PriceCalculationService $priceService
    ) {}

    public function execute(array $data, string $activeRoleCode, ?Badge $badge): array
    {

        $variant = ProductVariant::with('product.offers')->findOrFail($data['variant_id']);
        $factory = Factory::findOrFail($data['factory_id']);
        $location = FactoryLocation::findOrFail($data['location_id']);
        $customerType = CustomerType::where(CustomerType::CODE, $activeRoleCode)->first();
        $withVat = (bool) ($data['with_vat'] ?? false);

        $unitDto = $this->priceService->calculatePrice(
            variant: $variant,
            factory: $factory,
            location: $location,
            customerType: $customerType,
            badge: $badge,
            filterCategoryIds: null,
            withVAT: $withVat,
            filterCategoryCode: $activeRoleCode
        );

        $physicalData = $this->calculatePhysicalData($data['quantities']);
        $totalWeight = $physicalData['total_weight'];

        $unitPrice = (float) $unitDto->finalPrice;
        $unitVat = (float) ($unitDto->vatAmount ?? 0.0);

        // finalPrice is already the EX-VAT payable price (priceWithVat = finalPrice +
        // vatAmount). The subtotal is therefore weight × finalPrice; VAT is added on top.
        // The previous `weight × (finalPrice − vat)` subtracted VAT from an ex-VAT price,
        // which cancelled the VAT and made final_price omit it when with_vat = true.
        $lineSubtotal = $totalWeight * $unitPrice;
        $totalVat = $totalWeight * $unitVat;

        $offers = $this->getEligibleOffers($variant->product, $totalWeight);
        $offerDiscount = $this->calculateOfferDiscount($lineSubtotal, $offers);

        // Spec ("پروسه خرید"): VAT applies to the POST-discount base, so an offer that
        // reduces the subtotal reduces VAT proportionally — mirroring CartPricingCalculator
        // so the estimate equals the cart total. Otherwise the buyer is shown VAT on the
        // pre-offer amount and the pre-invoice over-states the tax.
        $afterOffer = max(0.0, $lineSubtotal - $offerDiscount);
        $effectiveVat = $totalVat;
        if ($offerDiscount > 0 && $lineSubtotal > 0) {
            $effectiveVat = $totalVat * ($afterOffer / $lineSubtotal);
        }

        // ۵. محاسبه نهایی
        $finalPrice = $afterOffer + $effectiveVat;

        return [
            'unit_dto' => $unitDto,
            'order_details' => [
                'total_count' => (string) $physicalData['total_count'],
                'total_weight' => (string) $totalWeight,
                'breakdown' => $physicalData['breakdown'],
            ],
            'offers' => $offers,
            'financials' => [
                'subtotal' => (string) $lineSubtotal,
                'discount_amount' => (string) $offerDiscount,
                'vat_amount' => (string) $effectiveVat,
                'final_price' => (string) $finalPrice,
                'with_vat' => $withVat,
            ],
        ];
    }

    /**
     * محاسبه مستقل ویژگی‌های فیزیکی برای ایزوله کردن خطاهای محاسبه
     */
    private function calculatePhysicalData(array $quantities): array
    {
        $totalCount = 0.0;
        $totalWeight = 0.0;
        $breakdown = [];

        $orderUnits = OrderUnit::allCached();

        foreach ($quantities as $unitIdToString => $qty) {
            $unitId = (int) $unitIdToString;
            $currentQty = (float) $qty;
            $unit = $orderUnits->get($unitId);

            if (! $unit || $currentQty <= 0) {
                continue;
            }

            $weightPerUnit = (float) $unit->amount;
            $totalCount += $currentQty;
            $totalWeight += $currentQty * $weightPerUnit;

            $breakdown[] = [
                'unit_id' => $unit->id,
                'unit_name' => $unit->name,
                'quantity' => (string) $currentQty,
                'unit_weight' => (string) $weightPerUnit,
                'total_weight' => (string) ($currentQty * $weightPerUnit),
            ];
        }

        return [
            'total_count' => $totalCount,
            'total_weight' => $totalWeight,
            'breakdown' => $breakdown,
        ];
    }

    private function getEligibleOffers($product, float $qty): Collection
    {
        $now = now();

        // فیلتر کردن کالکشنِ از پیش لود شده برای جلوگیری از اجرای کوئری در دیتابیس
        return $product->offers->filter(function ($offer) use ($qty, $now) {
            $isCurrent = (! $offer->start_date || $offer->start_date <= $now) &&
                         (! $offer->end_date || $offer->end_date >= $now);

            return $isCurrent && $offer->minimum_quantity <= $qty;
        })->values();
    }

    private function calculateOfferDiscount(float $subtotal, Collection $offers): float
    {
        $discount = 0.0;

        foreach ($offers as $offer) {
            if ($offer->type === 'percentage') {
                $discount += $subtotal * ((float) $offer->value / 100);
            } elseif ($offer->type === 'fixed_amount') {
                $discount += (float) $offer->value;
            }
        }

        return min($discount, $subtotal);
    }
}
