<?php

namespace Tests\Feature\Shipping;

use App\Models\ShippingRate;
use App\Services\Shipping\ShippingCostCalculator;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\Feature\Pricing\CreatesPricingFixtures;
use Tests\TestCase;

/**
 * Freight is grouped into shipments by (origin factory location → destination address)
 * and each shipment is priced on its own route. Covers multi-origin AND multi-destination.
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
    public function it_prices_each_factory_origin_separately_to_one_destination(): void
    {
        $province = 100;
        $city = 5;

        $kavir = $this->makeLocation($this->makeFactory());
        $zagros = $this->makeLocation($this->makeFactory());
        $this->rate($kavir->id, $province, null, flat: 500_000, perKg: 100); // 1000kg → 600,000
        $this->rate($zagros->id, $province, null, flat: 300_000, perKg: 50);  // 2000kg → 400,000

        $lines = [
            'rebar' => ['factory_location_id' => $kavir->id, 'address_id' => 9, 'destination_province_id' => $province, 'destination_city_id' => $city, 'weight' => 1000],
            'brick' => ['factory_location_id' => $zagros->id, 'address_id' => 9, 'destination_province_id' => $province, 'destination_city_id' => $city, 'weight' => 2000],
        ];

        $quote = $this->calc->quote($lines);

        $this->assertCount(2, $quote->shipments, 'two origins → two shipments');
        $this->assertEqualsWithDelta(600_000, $quote->perLine['rebar'], 1);
        $this->assertEqualsWithDelta(400_000, $quote->perLine['brick'], 1);
        $this->assertEqualsWithDelta(1_000_000, $quote->total, 1);
    }

    /** @test */
    public function it_ships_to_multiple_destinations_each_with_its_own_cost(): void
    {
        // Same origin factory, two different destination provinces → two shipments.
        $kavir = $this->makeLocation($this->makeFactory());
        $this->rate($kavir->id, 100, null, flat: 500_000, perKg: 0); // → Tehran
        $this->rate($kavir->id, 200, null, flat: 800_000, perKg: 0); // → Isfahan

        $lines = [
            'to_tehran' => ['factory_location_id' => $kavir->id, 'address_id' => 11, 'destination_province_id' => 100, 'destination_city_id' => null, 'weight' => 10],
            'to_isfahan' => ['factory_location_id' => $kavir->id, 'address_id' => 22, 'destination_province_id' => 200, 'destination_city_id' => null, 'weight' => 10],
        ];

        $quote = $this->calc->quote($lines);

        $this->assertCount(2, $quote->shipments, 'two destinations → two shipments');
        $this->assertEqualsWithDelta(500_000, $quote->perLine['to_tehran'], 1);
        $this->assertEqualsWithDelta(800_000, $quote->perLine['to_isfahan'], 1);
        $this->assertEqualsWithDelta(1_300_000, $quote->total, 1);
    }

    /** @test */
    public function a_city_specific_rate_overrides_the_province_fallback(): void
    {
        $province = 100;
        $city = 5;
        $loc = $this->makeLocation($this->makeFactory());

        $this->rate($loc->id, $province, null, flat: 900_000, perKg: 0);
        $this->rate($loc->id, $province, $city, flat: 100_000, perKg: 0);

        $quote = $this->calc->quote([
            'x' => ['factory_location_id' => $loc->id, 'address_id' => 1, 'destination_province_id' => $province, 'destination_city_id' => $city, 'weight' => 10],
        ]);

        $this->assertEqualsWithDelta(100_000, $quote->total, 1);
    }

    /** @test */
    public function an_origin_with_no_rate_costs_zero(): void
    {
        $loc = $this->makeLocation($this->makeFactory());

        $quote = $this->calc->quote([
            'x' => ['factory_location_id' => $loc->id, 'address_id' => 1, 'destination_province_id' => 999, 'destination_city_id' => 888, 'weight' => 10],
        ]);

        $this->assertEqualsWithDelta(0, $quote->total, 0.01);
        $this->assertEqualsWithDelta(0, $quote->perLine['x'], 0.01);
    }

    /** @test */
    public function it_allocates_a_shipment_cost_across_lines_by_weight(): void
    {
        $province = 100;
        $loc = $this->makeLocation($this->makeFactory());
        $this->rate($loc->id, $province, null, flat: 0, perKg: 10); // 1000kg → 10,000

        // two lines, same origin + same destination → one shipment, split by weight
        $lines = [
            'a' => ['factory_location_id' => $loc->id, 'address_id' => 7, 'destination_province_id' => $province, 'destination_city_id' => null, 'weight' => 300],
            'b' => ['factory_location_id' => $loc->id, 'address_id' => 7, 'destination_province_id' => $province, 'destination_city_id' => null, 'weight' => 700],
        ];

        $quote = $this->calc->quote($lines);

        $this->assertCount(1, $quote->shipments, 'same origin+destination → one shipment');
        $this->assertEqualsWithDelta(10_000, $quote->total, 1);
        $this->assertEqualsWithDelta(3_000, $quote->perLine['a'], 1);
        $this->assertEqualsWithDelta(7_000, $quote->perLine['b'], 1);
        $this->assertEqualsWithDelta(10_000, $quote->perLine['a'] + $quote->perLine['b'], 0.01);
    }
}
