<?php

namespace App\Actions\Order;

use App\Models\Badge;
use App\Models\CustomerType;
use App\Models\Factory;
use App\Models\FactoryLocation;
use App\Models\Offer;
use App\Models\ProductVariant;
use App\Services\PriceCalculationService;
use Illuminate\Support\Collection;

class CalculateOrderEstimateAction
{
    public function __construct(
        private readonly PriceCalculationService $priceService
    ) {}

    public function execute(array $data, string $activeRoleCode, ?Badge $badge): array
    {
        // ۱. یافتن مدل‌ها
        $variant  = ProductVariant::findOrFail($data['variant_id']);
        $factory  = Factory::findOrFail($data['factory_id']);
        $location = FactoryLocation::findOrFail($data['location_id']);
        $customerType = CustomerType::where(CustomerType::CODE, $activeRoleCode)->first();
        $withVat = (bool)($data['with_vat'] ?? false);

        // ۲. محاسبه قیمت واحد (از طریق سرویس)
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

        // ۳. محاسبه قیمت کل (وزن * قیمت واحد)
        $orderDetails = $this->priceService->calculateTotalPrice(
            quantities: $data['quantities'],
            unitPrice: $unitDto->finalPrice,
            withVAT: $withVat
        );

        // ۴. محاسبه آفرها (تخفیف حجمی)
        $offers = $this->getEligibleOffers($variant->product, (float)$orderDetails['total_count']);
        $discountedPrice = $this->applyOffers((float)$orderDetails['subtotal'], $offers);

        // ۵. محاسبه نهایی مالیات و جمع کل
        $vatAmount = $withVat ? $discountedPrice * ((float)$orderDetails['vat_rate'] / 100) : 0.0;
        $finalPrice = $discountedPrice + $vatAmount;

        return [
            'unit_dto'      => $unitDto,       // برای نمایش جزئیات قیمت واحد
            'order_details' => $orderDetails,  // وزن کل، تعداد کل
            'offers'        => $offers,
            'financials'    => [
                'subtotal'         => $orderDetails['subtotal'],
                'discount_amount'  => (string)($orderDetails['subtotal'] - $discountedPrice),
                'discounted_price' => (string)$discountedPrice,
                'vat_amount'       => (string)$vatAmount,
                'vat_rate'         => (string)($withVat ? $orderDetails['vat_rate'] : 0.0),
                'final_price'      => (string)$finalPrice,
                'with_vat'         => $withVat,
            ]
        ];
    }

    private function getEligibleOffers($product, float $qty)
    {
        return $product->offers()->current()
            ->where(Offer::MINIMUM_QUANTITY, '<=', $qty)
            ->get();
    }

    private function applyOffers(float $price, Collection $offers): float
    {
        foreach ($offers as $offer) {
            $price = match ($offer->type) {
                'percentage'   => $price - ($price * (float)$offer->value / 100),
                'fixed_amount' => $price - (float)$offer->value,
                default        => $price,
            };
        }
        return max(0.0, $price);
    }
}
