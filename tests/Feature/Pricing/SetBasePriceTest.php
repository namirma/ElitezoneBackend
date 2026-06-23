<?php

namespace Tests\Feature\Pricing;

use App\Services\Pricing\Pipes\SetBasePrice;
use App\Services\Pricing\PricingContext;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * SetBasePrice reads the latest ProductPrice row for the given
 * variant/factory/location triple and populates baseGeneralPrice,
 * baseSpecialPrice, listPrice, payablePrice.
 * Missing prices fall back to 0.0.
 */
class SetBasePriceTest extends TestCase
{
    use CreatesPricingFixtures;
    use DatabaseTransactions;

    private SetBasePrice $pipe;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pipe = new SetBasePrice;
        // The pipe caches lookups — flush each test to keep them independent
        Cache::flush();
    }

    /** @test */
    public function it_loads_general_and_special_prices_from_database(): void
    {
        $variant = $this->makeVariant();
        $factory = $this->makeFactory();
        $location = $this->makeLocation($factory);

        $this->makePrice($variant, $factory, $location, general: 1_500_000, special: 1_200_000);

        $ctx = new PricingContext(variant: $variant, factory: $factory, location: $location);

        $this->pipe->handle($ctx, fn ($c) => $c);

        $this->assertSame(1_500_000.0, $ctx->baseGeneralPrice);
        $this->assertSame(1_200_000.0, $ctx->baseSpecialPrice);
    }

    /** @test */
    public function it_uses_special_price_for_payable_when_available(): void
    {
        $variant = $this->makeVariant();
        $factory = $this->makeFactory();
        $location = $this->makeLocation($factory);

        $this->makePrice($variant, $factory, $location, general: 1_500_000, special: 1_200_000);

        $ctx = new PricingContext(variant: $variant, factory: $factory, location: $location);

        $this->pipe->handle($ctx, fn ($c) => $c);

        $this->assertSame(1_200_000.0, $ctx->payablePrice, 'Special path = special price when > 0');
        $this->assertSame(1_500_000.0, $ctx->listPrice, 'List path = general price');
    }

    /** @test */
    public function it_falls_back_to_general_price_when_special_is_zero(): void
    {
        $variant = $this->makeVariant();
        $factory = $this->makeFactory();
        $location = $this->makeLocation($factory);

        $this->makePrice($variant, $factory, $location, general: 1_500_000, special: 0);

        $ctx = new PricingContext(variant: $variant, factory: $factory, location: $location);

        $this->pipe->handle($ctx, fn ($c) => $c);

        $this->assertSame(1_500_000.0, $ctx->payablePrice, 'Falls back to general when special=0');
    }

    /** @test */
    public function it_returns_zero_when_no_price_row_exists(): void
    {
        $variant = $this->makeVariant();
        $factory = $this->makeFactory();
        $location = $this->makeLocation($factory);

        // intentionally NO ProductPrice row

        $ctx = new PricingContext(variant: $variant, factory: $factory, location: $location);

        $this->pipe->handle($ctx, fn ($c) => $c);

        $this->assertSame(0.0, $ctx->baseGeneralPrice);
        $this->assertSame(0.0, $ctx->baseSpecialPrice);
        $this->assertSame(0.0, $ctx->payablePrice);
    }

    /** @test */
    public function it_reflects_an_updated_price_after_cache_flush(): void
    {
        // A single (variant, factory, location) triple is uniquely indexed,
        // so the "latest('updated_at')" branch is exercised by mutating an
        // existing row and re-running the pipe (with cache flushed between).
        $variant = $this->makeVariant();
        $factory = $this->makeFactory();
        $location = $this->makeLocation($factory);

        $price = $this->makePrice($variant, $factory, $location, general: 800_000, special: 700_000);

        $ctx = new PricingContext(variant: $variant, factory: $factory, location: $location);
        $this->pipe->handle($ctx, fn ($c) => $c);
        $this->assertSame(800_000.0, $ctx->baseGeneralPrice);

        // Update the row and re-run
        $price->update(['general_price' => 1_500_000, 'special_price' => 1_200_000]);
        Cache::flush();

        $ctx2 = new PricingContext(variant: $variant, factory: $factory, location: $location);
        $this->pipe->handle($ctx2, fn ($c) => $c);

        $this->assertSame(1_500_000.0, $ctx2->baseGeneralPrice, 'Updated row must be reflected');
    }
}
