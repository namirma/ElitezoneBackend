<?php

namespace App\Services;

use App\DataTransferObjects\Pricing\PriceCalculationResult;
use App\Models\Badge;
use App\Models\CustomerType;
use App\Models\Factory;
use App\Models\FactoryLocation;
use App\Models\OrderUnit;
use App\Models\PriceSetting;
use App\Models\ProductVariant;
use App\Services\Pricing\Pipes\ApplyPriceFilters;
use App\Services\Pricing\Pipes\ApplyVariantDiscount;
use App\Services\Pricing\Pipes\ApplyVat;
use App\Services\Pricing\Pipes\SetBasePrice;
use App\Services\Pricing\PricingContext;
use Illuminate\Pipeline\Pipeline;

class PriceCalculationService
{
    /**
     * محاسبه قیمت واحد محصول با استفاده از الگوی Pipeline
     */
    public function calculatePrice(
        ProductVariant  $variant,
        Factory         $factory,
        FactoryLocation $location,
        ?CustomerType   $customerType = null,
        ?Badge          $badge = null,
        ?array          $filterCategoryIds = null,
        bool            $withVAT = false,
        ?string         $filterCategoryCode = null
    ): PriceCalculationResult {
        // ۱. ایجاد کانتکست (Context)
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

        // ۲. تعریف مراحل (Pipes)
        $pipes = [
            SetBasePrice::class,        // دریافت قیمت پایه از کش/دیتابیس
            ApplyVariantDiscount::class, // اعمال تخفیف مستقیم واریانت
            ApplyPriceFilters::class,   // اعمال فیلترهای دینامیک (عمومی/ویژه)
            ApplyVat::class,            // اعمال ارزش افزوده
        ];

        // ۳. اجرای پایپ‌لاین
        return app(Pipeline::class)
            ->send($context)
            ->through($pipes)
            ->then(function (PricingContext $context) {
                return $context->toDto(); 
            });
    }


    public function calculateTotalPrice(array $quantities, float $unitPrice, bool $withVAT = false): array
    {
        $totalCount = 0.0;
        $totalWeight = 0.0;
        $breakdown = [];
        $orderUnits = OrderUnit::all()->keyBy(OrderUnit::ID);

        foreach ($quantities as $unitIdToString => $qty) {
            $unitId = (int) $unitIdToString;
            $currentQty = (float) $qty;
            $unit = $orderUnits->get($unitId);
            if (!$unit || $currentQty <= 0) continue;

            $weightPerUnit = (float) $unit->amount;
            $totalCount += $currentQty;
            $totalWeight += $currentQty * $weightPerUnit;

            $breakdown[] = [
                'unit_id'      => $unit->id,
                'unit_name'    => $unit->name,
                'quantity'     => (string) $currentQty,
                'unit_weight'  => (string) $weightPerUnit,
                'total_weight' => (string) ($currentQty * $weightPerUnit),
            ];
        }

        $subtotal = $totalWeight * $unitPrice;
        $vatAmount = 0.0;
        $vatRate = 0.0;

        if ($withVAT) {
            $vatRate = PriceSetting::getValueAsFloat(PriceSetting::CODE_VAT_RATE, 10.0);
            $vatAmount = $subtotal * ($vatRate / 100);
        }

        return [
            'total_count'    => (string) $totalCount,
            'total_weight'   => (string) $totalWeight,
            'unit_price'     => (string) $unitPrice,
            'subtotal'       => (string) $subtotal,
            'vat_rate'       => (string) ($withVAT ? $vatRate : 0.0),
            'vat_amount'     => (string) $vatAmount,
            'total_with_vat' => (string) ($subtotal + $vatAmount),
            'with_vat'       => $withVAT,
            'breakdown'      => $breakdown,
        ];
    }
}
