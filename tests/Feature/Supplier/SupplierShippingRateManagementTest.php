<?php

namespace Tests\Feature\Supplier;

use App\Models\Factory;
use App\Models\FactoryLocation;
use App\Models\ShippingRate;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Laratrust\Models\Role;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Self-service supplier shipping-rate management (/profile/supplier/shipping-rates).
 *
 * Mirrors AdminShippingRateController's route-uniqueness rules exactly; the only
 * addition is that factory_location_id (the route's origin) must belong to one of
 * the caller's own factories — pinned here the same way as the factory/location
 * and price panels.
 */
class SupplierShippingRateManagementTest extends TestCase
{
    use DatabaseTransactions;

    private function makeSupplier(): User
    {
        $supplier = User::factory()->create();
        $supplier->addRole(Role::firstOrCreate(['name' => 'supplier'], ['display_name' => 'Supplier']));

        return $supplier;
    }

    private function makeSupplierFactory(User $supplier): Factory
    {
        return Factory::create([
            Factory::SUPPLIER_USER_ID => $supplier->id,
            Factory::NAME => 'کارخانه تست',
            Factory::SLUG => 'factory-'.Str::random(10),
            Factory::IS_ACTIVE => true,
        ]);
    }

    private function makeLocation(Factory $factory): FactoryLocation
    {
        return FactoryLocation::create([
            FactoryLocation::FACTORY_ID => $factory->id,
            FactoryLocation::NAME => 'انبار تست',
            FactoryLocation::TYPE => 'warehouse',
        ]);
    }

    private function rateRow(FactoryLocation $location, array $overrides = []): array
    {
        return array_merge([
            'factory_location_id' => $location->id,
            'destination_province_id' => 8, // تهران
            'destination_city_id' => null,
            'flat_cost' => 500_000,
            'per_kg_cost' => 1_000,
            'cost_basis' => 'weight',
            'per_count_cost' => 0,
            'is_active' => true,
        ], $overrides);
    }

    // ── role.supplier middleware gate ──────────────────────────────────

    #[Test]
    public function a_non_supplier_cannot_list_shipping_rates(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/profile/supplier/shipping-rates')->assertStatus(403);
    }

    // ── Create ──────────────────────────────────────────────────────────

    #[Test]
    public function a_supplier_can_create_a_rate_for_their_own_location(): void
    {
        $supplier = $this->makeSupplier();
        $factory = $this->makeSupplierFactory($supplier);
        $location = $this->makeLocation($factory);

        Sanctum::actingAs($supplier);

        $this->postJson('/api/profile/supplier/shipping-rates', $this->rateRow($location))
            ->assertOk();

        $this->assertDatabaseHas('shipping_rates', [
            'factory_location_id' => $location->id,
            'destination_province_id' => 8,
        ]);
    }

    #[Test]
    public function a_supplier_cannot_create_a_rate_using_anothers_location(): void
    {
        $supplierA = $this->makeSupplier();
        $supplierB = $this->makeSupplier();
        $theirFactory = $this->makeSupplierFactory($supplierB);
        $theirLocation = $this->makeLocation($theirFactory);

        Sanctum::actingAs($supplierA);

        $this->postJson('/api/profile/supplier/shipping-rates', $this->rateRow($theirLocation))
            ->assertStatus(422);

        $this->assertDatabaseMissing('shipping_rates', ['factory_location_id' => $theirLocation->id]);
    }

    #[Test]
    public function a_duplicate_route_is_rejected(): void
    {
        $supplier = $this->makeSupplier();
        $factory = $this->makeSupplierFactory($supplier);
        $location = $this->makeLocation($factory);

        ShippingRate::create($this->rateRow($location));

        Sanctum::actingAs($supplier);

        $this->postJson('/api/profile/supplier/shipping-rates', $this->rateRow($location))
            ->assertStatus(422);
    }

    #[Test]
    public function a_city_specific_row_can_coexist_with_the_province_level_fallback(): void
    {
        $supplier = $this->makeSupplier();
        $factory = $this->makeSupplierFactory($supplier);
        $location = $this->makeLocation($factory);

        ShippingRate::create($this->rateRow($location)); // province-level (city = null)

        Sanctum::actingAs($supplier);

        // Same location + province, but a specific city — must NOT collide with the null-city row.
        $this->postJson('/api/profile/supplier/shipping-rates', $this->rateRow($location, ['destination_city_id' => 145]))
            ->assertOk();

        $this->assertDatabaseHas('shipping_rates', [
            'factory_location_id' => $location->id,
            'destination_province_id' => 8,
            'destination_city_id' => 145,
        ]);
    }

    // ── Isolation ───────────────────────────────────────────────────────

    #[Test]
    public function a_supplier_only_sees_their_own_rates_in_the_list(): void
    {
        $supplierA = $this->makeSupplier();
        $supplierB = $this->makeSupplier();

        $locationA = $this->makeLocation($this->makeSupplierFactory($supplierA));
        $locationB = $this->makeLocation($this->makeSupplierFactory($supplierB));

        $mine = ShippingRate::create($this->rateRow($locationA));
        $theirs = ShippingRate::create($this->rateRow($locationB));

        Sanctum::actingAs($supplierA);

        $ids = collect($this->getJson('/api/profile/supplier/shipping-rates')->assertOk()->json('data'))
            ->pluck('id');

        $this->assertTrue($ids->contains($mine->id));
        $this->assertFalse($ids->contains($theirs->id));
    }

    #[Test]
    public function a_supplier_cannot_update_anothers_rate(): void
    {
        $supplierA = $this->makeSupplier();
        $supplierB = $this->makeSupplier();
        $theirLocation = $this->makeLocation($this->makeSupplierFactory($supplierB));
        $theirRate = ShippingRate::create($this->rateRow($theirLocation));

        Sanctum::actingAs($supplierA);

        $this->putJson("/api/profile/supplier/shipping-rates/{$theirRate->id}", $this->rateRow($theirLocation, ['flat_cost' => 1]))
            ->assertStatus(403);

        $this->assertDatabaseHas('shipping_rates', ['id' => $theirRate->id, 'flat_cost' => 500_000]);
    }

    #[Test]
    public function a_supplier_cannot_delete_anothers_rate(): void
    {
        $supplierA = $this->makeSupplier();
        $supplierB = $this->makeSupplier();
        $theirLocation = $this->makeLocation($this->makeSupplierFactory($supplierB));
        $theirRate = ShippingRate::create($this->rateRow($theirLocation));

        Sanctum::actingAs($supplierA);

        $this->deleteJson("/api/profile/supplier/shipping-rates/{$theirRate->id}")->assertStatus(403);
        $this->assertDatabaseHas('shipping_rates', ['id' => $theirRate->id]);
    }

    // ── Update ──────────────────────────────────────────────────────────

    #[Test]
    public function a_supplier_can_update_their_own_rate(): void
    {
        $supplier = $this->makeSupplier();
        $location = $this->makeLocation($this->makeSupplierFactory($supplier));
        $rate = ShippingRate::create($this->rateRow($location));

        Sanctum::actingAs($supplier);

        $this->putJson("/api/profile/supplier/shipping-rates/{$rate->id}", $this->rateRow($location, ['flat_cost' => 750_000]))
            ->assertOk();

        $this->assertDatabaseHas('shipping_rates', ['id' => $rate->id, 'flat_cost' => 750_000]);
    }

    #[Test]
    public function a_supplier_can_toggle_their_own_rate(): void
    {
        $supplier = $this->makeSupplier();
        $location = $this->makeLocation($this->makeSupplierFactory($supplier));
        $rate = ShippingRate::create($this->rateRow($location, ['is_active' => true]));

        Sanctum::actingAs($supplier);

        $this->postJson("/api/profile/supplier/shipping-rates/{$rate->id}/toggle")->assertOk();

        $this->assertDatabaseHas('shipping_rates', ['id' => $rate->id, 'is_active' => false]);
    }
}
