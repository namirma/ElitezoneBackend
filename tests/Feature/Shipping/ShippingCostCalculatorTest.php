<?php

namespace Tests\Feature\Shipping;

use App\Enums\Shipping\ShippingCostBasis;
use App\Models\ShippingRate;
use App\Services\Shipping\ShippingCostCalculator;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Pricing\CreatesPricingFixtures;
use Tests\TestCase;

/**
 * Freight is grouped into shipments by (origin factory location → destination address)
 * and each shipment is priced on its own route. Covers multi-origin AND multi-destination,
 * and both billing bases: by WEIGHT (per kg, e.g. rebar) and by COUNT (per item, e.g. brick).
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

    private function rate(
        int $locationId,
        int $provinceId,
        ?int $cityId,
        float $flat = 0,
        float $perKg = 0,
        string $basis = 'weight',
        float $perCount = 0,
    ): void {
        ShippingRate::create([
            ShippingRate::FACTORY_LOCATION_ID => $locationId,
            ShippingRate::DESTINATION_PROVINCE_ID => $provinceId,
            ShippingRate::DESTINATION_CITY_ID => $cityId,
            ShippingRate::FLAT_COST => $flat,
            ShippingRate::PER_KG_COST => $perKg,
            ShippingRate::COST_BASIS => $basis,
            ShippingRate::PER_COUNT_COST => $perCount,
            ShippingRate::IS_ACTIVE => true,
        ]);
    }

    #[Test]
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

    #[Test]
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

    #[Test]
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

    #[Test]
    public function an_origin_with_no_rate_costs_zero(): void
    {
        $loc = $this->makeLocation($this->makeFactory());

        $quote = $this->calc->quote([
            'x' => ['factory_location_id' => $loc->id, 'address_id' => 1, 'destination_province_id' => 999, 'destination_city_id' => 888, 'weight' => 10],
        ]);

        $this->assertEqualsWithDelta(0, $quote->total, 0.01);
        $this->assertEqualsWithDelta(0, $quote->perLine['x'], 0.01);
    }

    #[Test]
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

    #[Test]
    public function it_bills_by_count_when_the_rate_basis_is_count(): void
    {
        $province = 100;
        $loc = $this->makeLocation($this->makeFactory());
        // brick: 50,000 flat + 200 per piece, billed by COUNT (weight must be ignored).
        $this->rate($loc->id, $province, null, flat: 50_000, basis: 'count', perCount: 200);

        $quote = $this->calc->quote([
            'brick' => [
                'factory_location_id' => $loc->id,
                'address_id' => 1,
                'destination_province_id' => $province,
                'destination_city_id' => null,
                'weight' => 999_999, // huge weight that MUST NOT affect a count-billed rate
                'count' => 1000,
            ],
        ]);

        // 50,000 + 200 × 1000 = 250,000
        $this->assertEqualsWithDelta(250_000, $quote->total, 1);
        $this->assertEqualsWithDelta(250_000, $quote->perLine['brick'], 1);
        $this->assertSame(ShippingCostBasis::COUNT, $quote->shipments[0]->costBasis);
        $this->assertEqualsWithDelta(1000, $quote->shipments[0]->count, 0.001);
    }

    #[Test]
    public function it_allocates_a_count_billed_shipment_across_lines_by_count(): void
    {
        $province = 100;
        $loc = $this->makeLocation($this->makeFactory());
        $this->rate($loc->id, $province, null, basis: 'count', perCount: 10); // 1000 pcs → 10,000

        // Two lines, same origin+destination → one shipment, split by COUNT not weight.
        // Weights are equal so a weight-split would give 5,000/5,000 — the test proves
        // the cost follows count (200:800).
        $lines = [
            'a' => ['factory_location_id' => $loc->id, 'address_id' => 7, 'destination_province_id' => $province, 'destination_city_id' => null, 'weight' => 5, 'count' => 200],
            'b' => ['factory_location_id' => $loc->id, 'address_id' => 7, 'destination_province_id' => $province, 'destination_city_id' => null, 'weight' => 5, 'count' => 800],
        ];

        $quote = $this->calc->quote($lines);

        $this->assertCount(1, $quote->shipments);
        $this->assertEqualsWithDelta(10_000, $quote->total, 1);
        $this->assertEqualsWithDelta(2_000, $quote->perLine['a'], 1);
        $this->assertEqualsWithDelta(8_000, $quote->perLine['b'], 1);
        $this->assertEqualsWithDelta(10_000, $quote->perLine['a'] + $quote->perLine['b'], 0.01);
    }

    #[Test]
    public function it_mixes_weight_billed_and_count_billed_shipments_in_one_order(): void
    {
        // Rebar billed by weight, brick billed by count, shipped to the same destination
        // from two different factories → two shipments, each on its own basis.
        $province = 100;
        $rebarLoc = $this->makeLocation($this->makeFactory());
        $brickLoc = $this->makeLocation($this->makeFactory());

        $this->rate($rebarLoc->id, $province, null, perKg: 100);                   // weight basis (default)
        $this->rate($brickLoc->id, $province, null, basis: 'count', perCount: 500); // count basis

        $lines = [
            'rebar' => ['factory_location_id' => $rebarLoc->id, 'address_id' => 9, 'destination_province_id' => $province, 'destination_city_id' => null, 'weight' => 1000, 'count' => 50],
            'brick' => ['factory_location_id' => $brickLoc->id, 'address_id' => 9, 'destination_province_id' => $province, 'destination_city_id' => null, 'weight' => 3000, 'count' => 200],
        ];

        $quote = $this->calc->quote($lines);

        $this->assertCount(2, $quote->shipments);
        $this->assertEqualsWithDelta(100_000, $quote->perLine['rebar'], 1); // 100 × 1000 kg
        $this->assertEqualsWithDelta(100_000, $quote->perLine['brick'], 1); // 500 × 200 pcs
        $this->assertEqualsWithDelta(200_000, $quote->total, 1);
    }

    #[Test]
    public function it_defaults_to_weight_basis_for_legacy_rows_without_a_basis(): void
    {
        // A row created with only flat + per_kg (no basis arg) behaves exactly as before.
        $province = 100;
        $loc = $this->makeLocation($this->makeFactory());
        $this->rate($loc->id, $province, null, flat: 0, perKg: 10); // weight-only legacy shape

        $quote = $this->calc->quote([
            'x' => ['factory_location_id' => $loc->id, 'address_id' => 1, 'destination_province_id' => $province, 'destination_city_id' => null, 'weight' => 1000, 'count' => 7],
        ]);

        $this->assertEqualsWithDelta(10_000, $quote->total, 1); // 10 × 1000 kg, count ignored
        $this->assertSame(ShippingCostBasis::WEIGHT, $quote->shipments[0]->costBasis);
    }
}
