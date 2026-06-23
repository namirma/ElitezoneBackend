<?php

namespace App\Services;

use App\DataTransferObjects\Pricing\PriceCalculationResult;
use App\Models\Badge;
use App\Models\CustomerType;
use App\Models\Factory;
use App\Models\FactoryLocation;
use App\Models\OrderUnit;
use App\Models\ProductVariant;
use App\Services\Pricing\Pipes\ApplyPriceFilters;
use App\Services\Pricing\Pipes\ApplyVariantDiscount;
use App\Services\Pricing\Pipes\ApplyVat;
use App\Services\Pricing\Pipes\SetBasePrice;
use App\Services\Pricing\PricingContext;
use Illuminate\Pipeline\Pipeline;

class PriceCalculationService
{
    public function calculatePrice(
        ProductVariant $variant,
        Factory $factory,
        FactoryLocation $location,
        ?CustomerType $customerType = null,
        ?Badge $badge = null,
        ?array $filterCategoryIds = null,
        bool $withVAT = false,
        ?string $filterCategoryCode = null
    ): PriceCalculationResult {
        $context = new PricingContext(
            variant: $variant,
            factory: $factory,
            location: $location,
            customerType: $customerType,
            badge: $badge,
            filterCategoryIds: $filterCategoryIds,
            withVat: $withVAT,
            filterCategoryCode: $filterCategoryCode
        );

        $pipes = [
            SetBasePrice::class,
            ApplyVariantDiscount::class,
            ApplyPriceFilters::class,
            ApplyVat::class, // مالیات فقط یکبار اینجا محاسبه می‌شود
        ];

        return app(Pipeline::class)
            ->send($context)
            ->through($pipes)
            ->then(fn (PricingContext $context) => $context->toDto());
    }

    /**
     * محاسبه وزن و تعداد کل بر اساس نگاشت واحدها
     * (مالیات از اینجا حذف شد تا مالیات مضاعف اعمال نشود)
     */
    public function calculateTotalWeightAndCount(array $quantities): array
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
}
