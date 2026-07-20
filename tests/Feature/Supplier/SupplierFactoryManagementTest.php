<?php

namespace Tests\Feature\Supplier;

use App\Models\Factory;
use App\Models\FactoryLocation;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductPrice;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Laratrust\Models\Role;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Self-service supplier factory/warehouse management
 * (/profile/supplier/factories, /profile/supplier/factory-locations).
 *
 * Pins the isolation guarantee that is the whole point of this panel: a supplier
 * may only ever see/touch factories and locations tied to their own
 * factories.supplier_user_id — never another supplier's. Also pins the
 * FactoryResource fix — api_key/api_url must never leak to non-owners.
 */
class SupplierFactoryManagementTest extends TestCase
{
    use DatabaseTransactions;

    private function makeSupplier(): User
    {
        $supplier = User::factory()->create();
        $supplier->addRole(Role::firstOrCreate(['name' => 'supplier'], ['display_name' => 'Supplier']));

        return $supplier;
    }

    private function makeSupplierFactory(User $supplier, array $overrides = []): Factory
    {
        return Factory::create(array_merge([
            Factory::SUPPLIER_USER_ID => $supplier->id,
            Factory::NAME => 'کارخانه تست',
            Factory::SLUG => 'factory-'.Str::random(10),
            Factory::IS_ACTIVE => true,
        ], $overrides));
    }

    private function makeLocation(Factory $factory, array $overrides = []): FactoryLocation
    {
        return FactoryLocation::create(array_merge([
            FactoryLocation::FACTORY_ID => $factory->id,
            FactoryLocation::NAME => 'انبار تست',
            FactoryLocation::TYPE => 'warehouse',
        ], $overrides));
    }

    // ── role.supplier middleware gate ──────────────────────────────────

    #[Test]
    public function a_non_supplier_cannot_list_factories(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/profile/supplier/factories')->assertStatus(403);
    }

    #[Test]
    public function a_non_supplier_cannot_create_a_factory(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/profile/supplier/factories', [
            'name' => 'کارخانه من',
            'slug' => 'my-factory-'.Str::random(8),
        ])->assertStatus(403);
    }

    // ── Create ──────────────────────────────────────────────────────────

    #[Test]
    public function a_supplier_can_create_their_own_factory(): void
    {
        $supplier = $this->makeSupplier();
        Sanctum::actingAs($supplier);

        $response = $this->postJson('/api/profile/supplier/factories', [
            'name' => 'کارخانه من',
            'slug' => 'my-factory-'.Str::random(8),
        ])->assertCreated();

        $this->assertDatabaseHas('factories', [
            'id' => $response->json('data.id'),
            Factory::SUPPLIER_USER_ID => $supplier->id,
        ]);
    }

    #[Test]
    public function supplier_user_id_cannot_be_spoofed_on_create(): void
    {
        $supplier = $this->makeSupplier();
        $otherSupplier = $this->makeSupplier();
        Sanctum::actingAs($supplier);

        $response = $this->postJson('/api/profile/supplier/factories', [
            'name' => 'کارخانه من',
            'slug' => 'my-factory-'.Str::random(8),
            Factory::SUPPLIER_USER_ID => $otherSupplier->id,
        ])->assertCreated();

        $this->assertDatabaseHas('factories', [
            'id' => $response->json('data.id'),
            Factory::SUPPLIER_USER_ID => $supplier->id,
        ]);
    }

    // ── Isolation ───────────────────────────────────────────────────────

    #[Test]
    public function a_supplier_only_sees_their_own_factories_in_the_list(): void
    {
        $supplierA = $this->makeSupplier();
        $supplierB = $this->makeSupplier();

        $mine = $this->makeSupplierFactory($supplierA);
        $theirs = $this->makeSupplierFactory($supplierB);

        Sanctum::actingAs($supplierA);

        $ids = collect($this->getJson('/api/profile/supplier/factories')->assertOk()->json('data'))
            ->pluck('id');

        $this->assertTrue($ids->contains($mine->id));
        $this->assertFalse($ids->contains($theirs->id));
    }

    #[Test]
    public function a_supplier_cannot_view_anothers_factory(): void
    {
        $supplierA = $this->makeSupplier();
        $supplierB = $this->makeSupplier();
        $theirs = $this->makeSupplierFactory($supplierB);

        Sanctum::actingAs($supplierA);

        $this->getJson("/api/profile/supplier/factories/{$theirs->id}")->assertStatus(403);
    }

    #[Test]
    public function a_supplier_cannot_update_anothers_factory(): void
    {
        $supplierA = $this->makeSupplier();
        $supplierB = $this->makeSupplier();
        $theirs = $this->makeSupplierFactory($supplierB);

        Sanctum::actingAs($supplierA);

        $this->putJson("/api/profile/supplier/factories/{$theirs->id}", ['name' => 'تصرف‌شده'])
            ->assertStatus(403);

        $this->assertDatabaseHas('factories', [Factory::ID => $theirs->id, Factory::NAME => 'کارخانه تست']);
    }

    #[Test]
    public function a_supplier_cannot_delete_anothers_factory(): void
    {
        $supplierA = $this->makeSupplier();
        $supplierB = $this->makeSupplier();
        $theirs = $this->makeSupplierFactory($supplierB);

        Sanctum::actingAs($supplierA);

        $this->deleteJson("/api/profile/supplier/factories/{$theirs->id}")->assertStatus(403);
        $this->assertDatabaseHas('factories', [Factory::ID => $theirs->id]);
    }

    // ── Deletion guards (mirrors AdminFactory[Location]Controller) ─────

    #[Test]
    public function a_factory_with_locations_cannot_be_deleted(): void
    {
        $supplier = $this->makeSupplier();
        $factory = $this->makeSupplierFactory($supplier);
        $this->makeLocation($factory);

        Sanctum::actingAs($supplier);

        $this->deleteJson("/api/profile/supplier/factories/{$factory->id}")->assertStatus(422);
        $this->assertDatabaseHas('factories', [Factory::ID => $factory->id]);
    }

    #[Test]
    public function a_location_with_a_product_price_cannot_be_deleted(): void
    {
        $supplier = $this->makeSupplier();
        $factory = $this->makeSupplierFactory($supplier);
        $location = $this->makeLocation($factory);

        $category = ProductCategory::create([
            'name' => 'دسته تست',
            'slug' => 'test-cat-'.Str::random(6),
            'code' => 'TC_'.Str::random(6),
        ]);
        $product = Product::create([
            'cuid' => Str::random(20),
            'category_id' => $category->id,
            'name' => 'محصول تست',
            'slug' => 'test-product-'.Str::random(8),
            'sku' => 'SKU_'.Str::random(6),
        ]);
        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'name' => 'واریانت تست',
            'sku' => 'VSKU_'.Str::random(6),
        ]);
        ProductPrice::create([
            ProductPrice::PRODUCT_VARIANT_ID => $variant->id,
            ProductPrice::FACTORY_ID => $factory->id,
            ProductPrice::FACTORY_LOCATION_ID => $location->id,
            ProductPrice::GENERAL_PRICE => 1_000_000,
            ProductPrice::SPECIAL_PRICE => 900_000,
            ProductPrice::GOLDEN_FIGURE => 0,
        ]);

        Sanctum::actingAs($supplier);

        $this->deleteJson("/api/profile/supplier/factory-locations/{$location->id}")->assertStatus(422);
        $this->assertDatabaseHas('factory_locations', [FactoryLocation::ID => $location->id]);
    }

    // ── Locations ───────────────────────────────────────────────────────

    #[Test]
    public function a_supplier_can_create_a_location_under_their_own_factory(): void
    {
        $supplier = $this->makeSupplier();
        $factory = $this->makeSupplierFactory($supplier);
        Sanctum::actingAs($supplier);

        $this->postJson('/api/profile/supplier/factory-locations', [
            FactoryLocation::FACTORY_ID => $factory->id,
            FactoryLocation::NAME => 'انبار مرکزی',
            FactoryLocation::TYPE => 'warehouse',
        ])->assertCreated();

        $this->assertDatabaseHas('factory_locations', [
            FactoryLocation::FACTORY_ID => $factory->id,
            FactoryLocation::NAME => 'انبار مرکزی',
        ]);
    }

    #[Test]
    public function a_supplier_cannot_create_a_location_under_anothers_factory(): void
    {
        $supplierA = $this->makeSupplier();
        $supplierB = $this->makeSupplier();
        $theirFactory = $this->makeSupplierFactory($supplierB);

        Sanctum::actingAs($supplierA);

        $this->postJson('/api/profile/supplier/factory-locations', [
            FactoryLocation::FACTORY_ID => $theirFactory->id,
            FactoryLocation::NAME => 'انبار جعلی',
            FactoryLocation::TYPE => 'warehouse',
        ])->assertStatus(422);

        $this->assertDatabaseMissing('factory_locations', [FactoryLocation::FACTORY_ID => $theirFactory->id]);
    }

    #[Test]
    public function a_supplier_cannot_delete_anothers_location(): void
    {
        $supplierA = $this->makeSupplier();
        $supplierB = $this->makeSupplier();
        $theirFactory = $this->makeSupplierFactory($supplierB);
        $theirLocation = $this->makeLocation($theirFactory);

        Sanctum::actingAs($supplierA);

        $this->deleteJson("/api/profile/supplier/factory-locations/{$theirLocation->id}")->assertStatus(403);
        $this->assertDatabaseHas('factory_locations', [FactoryLocation::ID => $theirLocation->id]);
    }

    // ── Credential visibility (FactoryResource security fix) ───────────

    #[Test]
    public function api_key_is_visible_to_the_owning_supplier(): void
    {
        $supplier = $this->makeSupplier();
        $factory = $this->makeSupplierFactory($supplier, [Factory::API_KEY => 'secret-key-123']);

        Sanctum::actingAs($supplier);

        $this->getJson("/api/profile/supplier/factories/{$factory->id}")
            ->assertOk()
            ->assertJsonPath('data.api_key', 'secret-key-123');
    }

    #[Test]
    public function api_key_never_appears_on_the_public_factories_listing(): void
    {
        $supplier = $this->makeSupplier();
        $this->makeSupplierFactory($supplier, [Factory::API_KEY => 'secret-key-123']);

        // Public endpoint — no auth at all.
        $response = $this->getJson('/api/factories')->assertOk();

        $keys = collect($response->json('data'))->pluck('api_key')->filter();
        $this->assertTrue($keys->isEmpty(), 'api_key must never appear on the public factories listing');
    }
}
