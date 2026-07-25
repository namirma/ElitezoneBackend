<?php

namespace Tests\Feature\Admin;

use App\Models\Factory;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laratrust\Models\Role;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\GrantsAdminPermissions;
use Tests\TestCase;

/**
 * End-to-end coverage for the admin catalog-management suite: it walks the full
 * dependency chain (category → product → variant → price) plus the attribute,
 * price-filter and customer-type modules through the real middleware/validation
 * stack, and pins the auth gate + the delete guards.
 */
class AdminCatalogTest extends TestCase
{
    use DatabaseTransactions;
    use GrantsAdminPermissions;

    private function admin(): User
    {
        $user = User::factory()->create();
        $user->addRole(Role::firstOrCreate(['name' => 'admin'], ['display_name' => 'Admin']));

        return $this->grantAllAdminPermissions($user);
    }

    private function uid(): string
    {
        return substr(md5(uniqid('', true)), 0, 8);
    }

    // ── Auth gate ─────────────────────────────────────────────────────────

    #[Test]
    public function guest_is_unauthorized_on_catalog(): void
    {
        $this->getJson('/api/admin/products')->assertUnauthorized();
        $this->getJson('/api/admin/categories')->assertUnauthorized();
    }

    #[Test]
    public function non_admin_is_forbidden_on_catalog(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $this->getJson('/api/admin/products')->assertForbidden();
        $this->postJson('/api/admin/categories', [])->assertForbidden();
    }

    // ── Full vertical: category → product → variant → price ───────────────

    #[Test]
    public function admin_can_build_the_full_catalog_chain(): void
    {
        Sanctum::actingAs($this->admin());
        $u = $this->uid();

        // 1) category
        $category = $this->postJson('/api/admin/categories', [
            'name' => "دسته {$u}", 'slug' => "cat-{$u}", 'is_active' => true,
        ])->assertCreated()->json('data.id');

        // 2) product in that category
        $cuid = $this->postJson('/api/admin/products', [
            'category_id' => $category, 'name' => "محصول {$u}", 'slug' => "prod-{$u}", 'sku' => "SKU-{$u}",
        ])->assertCreated()->json('data.cuid');
        $this->assertNotNull($cuid);

        // 3) variant under the product (route bound by cuid)
        $variant = $this->postJson("/api/admin/products/{$cuid}/variants", [
            'name' => "واریانت {$u}", 'sku' => "VAR-{$u}", 'stock_quantity' => 100,
        ])->assertCreated()->json('data.id');

        // 4) factory + location (origin for the price)
        $factory = $this->postJson('/api/admin/factories', [
            'name' => "کارخانه {$u}", 'slug' => "fac-{$u}",
        ])->assertCreated()->json('data.id');

        $location = $this->postJson('/api/admin/factory-locations', [
            'factory_id' => $factory, 'name' => "انبار {$u}", 'type' => 'warehouse',
        ])->assertCreated()->json('data.id');

        // 5) price for (variant × factory × location)
        $this->postJson('/api/admin/product-prices', [
            'product_variant_id' => $variant, 'factory_id' => $factory, 'factory_location_id' => $location,
            'general_price' => 1_000_000, 'special_price' => 950_000, 'golden_figure' => 900_000,
        ])->assertCreated();

        $this->assertDatabaseHas('product_prices', [
            'product_variant_id' => $variant, 'factory_id' => $factory, 'general_price' => 1_000_000,
        ]);

        // 6) duplicate price for the same triple is rejected
        $this->postJson('/api/admin/product-prices', [
            'product_variant_id' => $variant, 'factory_id' => $factory, 'factory_location_id' => $location,
            'general_price' => 1, 'special_price' => 1, 'golden_figure' => 1,
        ])->assertStatus(422);
    }

    // ── Attribute chain + variant attribute sync ──────────────────────────

    #[Test]
    public function admin_can_manage_attributes_and_attach_to_a_variant(): void
    {
        Sanctum::actingAs($this->admin());
        $u = $this->uid();

        $type = $this->postJson('/api/admin/attribute-types', [
            'name' => "قطر {$u}", 'display_name' => 'قطر', 'input_type' => 'select',
        ])->assertCreated()->json('data.id');

        $attribute = $this->postJson('/api/admin/attributes', [
            'attribute_type_id' => $type, 'name' => "size-{$u}", 'display_name' => 'سایز',
        ])->assertCreated()->json('data.id');

        $value = $this->postJson('/api/admin/attribute-values', [
            'attribute_id' => $attribute, 'value' => '12', 'display_value' => '۱۲',
        ])->assertCreated()->json('data.id');

        // build a product + variant to attach onto
        $category = ProductCategory::create(['name' => "c{$u}", 'slug' => "c-{$u}", 'is_active' => true]);
        $cuid = $this->postJson('/api/admin/products', [
            'category_id' => $category->id, 'name' => "p{$u}", 'slug' => "p-{$u}", 'sku' => "S-{$u}",
        ])->json('data.cuid');
        $variant = $this->postJson("/api/admin/products/{$cuid}/variants", [
            'name' => "v{$u}", 'sku' => "V-{$u}",
        ])->json('data.id');

        $this->postJson("/api/admin/variants/{$variant}/attributes", [
            'attributes' => [['attribute_id' => $attribute, 'attribute_value_id' => $value]],
        ])->assertOk();

        $this->assertDatabaseHas('product_variant_attributes', [
            'product_variant_id' => $variant, 'attribute_id' => $attribute, 'attribute_value_id' => $value,
        ]);
    }

    // ── Price filters chain ───────────────────────────────────────────────

    #[Test]
    public function admin_can_manage_price_filters_and_categories(): void
    {
        Sanctum::actingAs($this->admin());
        $u = $this->uid();

        $type = $this->postJson('/api/admin/price-filter-types', [
            'name' => "نوع {$u}", 'code' => "ft-{$u}", 'apply_on' => 'both',
        ])->assertCreated()->json('data.id');

        $filter = $this->postJson('/api/admin/price-filters', [
            'price_filter_type_id' => $type, 'name' => "فیلتر {$u}", 'code' => "f-{$u}",
            'amount' => 5, 'is_percentage' => true,
        ])->assertCreated()->json('data.id');

        $cat = $this->postJson('/api/admin/price-filter-categories', [
            'name' => "دسته {$u}", 'code' => "fc-{$u}",
        ])->assertCreated()->json('data.id');

        $this->postJson("/api/admin/price-filter-categories/{$cat}/filters", [
            'filter_ids' => [$filter],
        ])->assertOk();

        $this->assertDatabaseHas('price_filter_category_items', [
            'price_filter_category_id' => $cat, 'price_filter_id' => $filter,
        ]);
    }

    // ── Customer type + price adjustment ──────────────────────────────────

    #[Test]
    public function admin_can_manage_customer_types_and_adjustments(): void
    {
        Sanctum::actingAs($this->admin());
        $u = $this->uid();

        $customerType = $this->postJson('/api/admin/customer-types', [
            'name' => "مشتری {$u}", 'code' => "ct-{$u}", 'has_elite_yar' => false,
        ])->assertCreated()->json('data.id');

        $this->postJson('/api/admin/price-adjustments', [
            'customer_type_id' => $customerType, 'badge_id' => null, 'adjustment_amount' => 1500, 'is_active' => true,
        ])->assertCreated();

        $this->assertDatabaseHas('price_adjustments', [
            'customer_type_id' => $customerType, 'adjustment_amount' => 1500,
        ]);

        // duplicate (same customer_type + null badge) rejected
        $this->postJson('/api/admin/price-adjustments', [
            'customer_type_id' => $customerType, 'badge_id' => null, 'adjustment_amount' => 9,
        ])->assertStatus(422);
    }

    // ── Delete guards ─────────────────────────────────────────────────────

    #[Test]
    public function category_with_products_cannot_be_deleted(): void
    {
        Sanctum::actingAs($this->admin());
        $u = $this->uid();

        $category = ProductCategory::create(['name' => "c{$u}", 'slug' => "c-{$u}", 'is_active' => true]);
        Product::create([
            'category_id' => $category->id, 'name' => "p{$u}", 'slug' => "p-{$u}", 'sku' => "S-{$u}",
        ]);

        $this->deleteJson("/api/admin/categories/{$category->id}")->assertStatus(422);
        $this->assertDatabaseHas('product_categories', ['id' => $category->id]);
    }

    #[Test]
    public function factory_with_locations_cannot_be_deleted(): void
    {
        Sanctum::actingAs($this->admin());
        $u = $this->uid();

        $factory = Factory::create(['name' => "f{$u}", 'slug' => "f-{$u}", 'is_active' => true]);
        $factory->locations()->create(['name' => "l{$u}", 'type' => 'warehouse', 'is_active' => true]);

        $this->deleteJson("/api/admin/factories/{$factory->id}")->assertStatus(422);
    }
}
