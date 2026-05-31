<?php

namespace Tests\Feature\Shipping;

use App\Models\ShippingRate;
use App\Services\Shipping\ShippingCostCalculator;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\Feature\Pricing\CreatesPricingFixtures;
use Tests\TestCase;

/**
 * Freight is computed per origin factory location → destination, then summed.
 * This is the manager's scenario: rebar from one factory and brick from another,
 * each with its own origin→destination cost.
 */
class ShippingCostCalculatorTest extends TestCase
{
    use CreatesPricingFixtures;
    use DatabaseTransactions;

    private ShippingCostCalculator $calc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->calc = app(ShippingCostCalculator::class);
    }

    private function rate(int $locationId, int $provinceId, ?int $cityId, float $flat, float $perKg): void
    {
        ShippingRate::create([
            ShippingRate::FACTORY_LOCATION_ID => $locationId,
            ShippingRate::DESTINATION_PROVINCE_ID => $provinceId,
            ShippingRate::DESTINATION_CITY_ID => $cityId,
            ShippingRate::FLAT_COST => $flat,
            ShippingRate::PER_KG_COST => $perKg,
            ShippingRate::IS_ACTIVE => true,
        ]);
    }

    /** @test */
    public function it_prices_each_factory_origin_separately_and_sums_the_total(): void
    {
        $provinceId = 100;
        $cityId = 5;

        $kavirLoc = $this->makeLocation($this->makeFactory());
        $zagrosLoc = $this->makeLocation($this->makeFactory());

        $this->rate($kavirLoc->id, $provinceId, null, flat: 500_000, perKg: 100);   // 1000kg → 600,000
        $this->rate($zagrosLoc->id, $provinceId, null, flat: 300_000, perKg: 50);    // 2000kg → 400,000

        $lines = [
            'rebar' => ['factory_location_id' => $kavirLoc->id, 'weight' => 1000],
            'brick' => ['factory_location_id' => $zagrosLoc->id, 'weight' => 2000],
        ];

        $quote = $this->calc->quote($lines, $provinceId, $cityId);

        $this->assertEqualsWithDelta(600_000, $quote->perLine['rebar'], 1);
        $this->assertEqualsWithDelta(400_000, $quote->perLine['brick'], 1);
        $this->assertEqualsWithDelta(1_000_000, $quote->total, 1);
        $this->assertEqualsWithDelta(600_000, $quote->perLocation[$kavirLoc->id], 1);
        $this->assertEqualsWithDelta(400_000, $quote->perLocation[$zagrosLoc->id], 1);
    }

    /** @test */
    public function a_city_specific_rate_overrides_the_province_fallback(): void
    {
        $provinceId = 100;
        $cityId = 5;
        $loc = $this->makeLocation($this->makeFactory());

        $this->rate($loc->id, $provinceId, null, flat: 900_000, perKg: 0);   // province fallback
        $this->rate($loc->id, $provinceId, $cityId, flat: 100_000, perKg: 0); // cheaper city-specific

        $quote = $this->calc->quote(['x' => ['factory_location_id' => $loc->id, 'weight' => 10]], $provinceId, $cityId);

        $this->assertEqualsWithDelta(100_000, $quote->total, 1);
    }

    /** @test */
    public function an_origin_with_no_rate_costs_zero(): void
    {
        $loc = $this->makeLocation($this->makeFactory());

        $quote = $this->calc->quote(['x' => ['factory_location_id' => $loc->id, 'weight' => 10]], 999, 888);

        $this->assertEqualsWithDelta(0, $quote->total, 0.01);
        $this->assertEqualsWithDelta(0, $quote->perLine['x'], 0.01);
    }

    /** @test */
    public function it_allocates_a_group_cost_across_lines_by_weight(): void
    {
        $provinceId = 100;
        $loc = $this->makeLocation($this->makeFactory());
        $this->rate($loc->id, $provinceId, null, flat: 0, perKg: 10); // 1000kg → 10,000

        $lines = [
            'a' => ['factory_location_id' => $loc->id, 'weight' => 300],
            'b' => ['factory_location_id' => $loc->id, 'weight' => 700],
        ];

        $quote = $this->calc->quote($lines, $provinceId, null);

        $this->assertEqualsWithDelta(10_000, $quote->total, 1);
        $this->assertEqualsWithDelta(3_000, $quote->perLine['a'], 1);
        $this->assertEqualsWithDelta(7_000, $quote->perLine['b'], 1);
        // parts must sum exactly to the group cost
        $this->assertEqualsWithDelta(10_000, $quote->perLine['a'] + $quote->perLine['b'], 0.01);
    }
}
