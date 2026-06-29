<?php

namespace Tests\Feature\Pricing;

use App\Models\Factory;
use App\Models\FactoryLocation;
use App\Models\PriceSetting;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductPrice;
use App\Models\ProductVariant;
use App\Services\Pricing\PricingContext;
use Illuminate\Support\Str;

/**
 * Shared fixtures for the pricing-pipeline tests.
 *
 * Each pipe needs a ProductVariant + Factory + FactoryLocation at minimum;
 * SetBasePrice and ApplyPriceFilters also need ProductPrice / PriceFilter rows.
 * This trait builds the minimum graph deterministically.
 */
trait CreatesPricingFixtures
{
    protected function makeFactory(): Factory
    {
        return Factory::create([
            'name' => 'کارخانه تست',
            'slug' => 'test-factory-'.Str::random(8),
        ]);
    }

    protected function makeLocation(Factory $factory): FactoryLocation
    {
        return FactoryLocation::create([
            'factory_id' => $factory->id,
            'name'       => 'انبار اصلی',
            'type'       => 'main',
        ]);
    }

    protected function makeProduct(): Product
    {
        $category = ProductCategory::create([
            'name' => 'دسته تست',
            'slug' => 'test-cat-'.Str::random(6),
            'code' => 'TC_'.Str::random(6),
        ]);

        return Product::create([
            'cuid'        => Str::random(20),
            'category_id' => $category->id,
            'name'        => 'محصول تست',
            'slug'        => 'test-product-'.Str::random(8),
            'sku'         => 'SKU_'.Str::random(6),
        ]);
    }

    protected function makeVariant(?Product $product = null, array $overrides = []): ProductVariant
    {
        $product ??= $this->makeProduct();

        return ProductVariant::create(array_merge([
            'product_id' => $product->id,
            'name'       => 'واریانت تست',
            'sku'        => 'VSKU_'.Str::random(6),
        ], $overrides));
    }

    protected function makePrice(
        ProductVariant $variant,
        Factory $factory,
        FactoryLocation $location,
        float $general = 1_000_000,
        float $special = 900_000,
    ): ProductPrice {
        return ProductPrice::create([
            'product_variant_id'  => $variant->id,
            'factory_id'          => $factory->id,
            'factory_location_id' => $location->id,
            'general_price'       => $general,
            'special_price'       => $special,
            'golden_figure'       => 0,
        ]);
    }

    protected function makeContext(?ProductVariant $variant = null): PricingContext
    {
        $factory  = $this->makeFactory();
        $location = $this->makeLocation($factory);
        $variant ??= $this->makeVariant();

        return new PricingContext(
            variant:  $variant,
            factory:  $factory,
            location: $location,
        );
    }

    /**
     * Ensure a VAT rate row exists in price_settings (used by ApplyVat).
     */
    protected function setVatRate(float $rate): void
    {
        PriceSetting::updateOrCreate(
            ['code' => PriceSetting::CODE_VAT_RATE],
            ['name' => 'VAT Rate', 'value' => (string) $rate],
        );
    }
}
