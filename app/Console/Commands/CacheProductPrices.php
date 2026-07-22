<?php

namespace App\Console\Commands;

use App\Models\ProductPriceCache;
use App\Models\ProductVariant;
use App\Services\PriceCalculationService;
use Illuminate\Console\Command;

class CacheProductPrices extends Command
{
    protected $signature = 'products:cache-prices';

    protected $description = 'Calculate and cache product prices for all defined roles';

    const ROLES = [
        'employer_without_elite_yar',
        'engineer_with_elite_yar',
    ];

    public function handle(PriceCalculationService $priceService)
    {
        $this->info('Starting price calculation...');

        $variants = ProductVariant::with(['prices.factory', 'prices.factoryLocation'])
            ->where(ProductVariant::IS_ACTIVE, true)
            ->get();

        $bar = $this->output->createProgressBar($variants->count());
        $bar->start();

        foreach ($variants as $variant) {
            foreach (self::ROLES as $roleCode) {
                $minPrice = null;

                foreach ($variant->prices as $priceRecord) {
                    $calculated = $priceService->calculatePrice(
                        $variant,
                        $priceRecord->factory,
                        $priceRecord->factoryLocation,
                        null,
                        null,
                        null,
                        false,
                        $roleCode
                    );

                    // calculatePrice یک DTO (PriceCalculationResult) برمی‌گرداند، نه آرایه؛
                    // دسترسی آرایه‌ای باعث fatal می‌شد. از پراپرتی finalPrice استفاده می‌کنیم.
                    $final = (float) $calculated->finalPrice;

                    if (is_null($minPrice) || $final < $minPrice) {
                        $minPrice = $final;
                    }
                }

                if (! is_null($minPrice)) {
                    ProductPriceCache::updateOrCreate(
                        [
                            ProductPriceCache::PRODUCT_VARIANT_ID => $variant->id,
                            ProductPriceCache::ROLE_CODE => $roleCode,
                        ],
                        [
                            ProductPriceCache::PRODUCT_ID => $variant->product_id,
                            ProductPriceCache::FINAL_PRICE => $minPrice,
                        ]
                    );
                }
            }
            $bar->advance();
        }

        $bar->finish();
        $this->newLine();
        $this->info('Prices cached successfully.');
    }
}
