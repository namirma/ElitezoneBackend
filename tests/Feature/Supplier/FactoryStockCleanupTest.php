<?php

namespace Tests\Feature\Supplier;

use App\Models\Factory;
use App\Models\FactoryLocation;
use App\Models\FactoryStock;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductVariant;
use App\Models\SupplierProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Laratrust\Models\Role;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\GrantsAdminPermissions;
use Tests\TestCase;

/**
 * حذفِ یک مبدأ باید رول‌آپِ موجودی را هم درست کند.
 *
 * هر سه کلید خارجیِ `factory_stocks` روی `onDelete('cascade')` است و cascadeِ
 * **سطح دیتابیس هیچ رویداد Eloquent نمی‌زند**. پس حذفِ یک انبار ردیف‌های موجودی
 * را بی‌صدا می‌برد در حالی که `product_variants.stock_quantity` روی جمعِ قدیمی
 * می‌ماند. و چون `InventoryService::availableAt()` برای هر سه‌تاییِ بدونِ ردیف به
 * همان ستون برمی‌گردد، آن عددِ متورم می‌تواند سفارشی را تأیید کند که پشتوانهٔ
 * موجودی ندارد.
 *
 * گاردِ `in_use` جلوی این را نمی‌گرفت: آن فقط `ProductPrice` را می‌دید، و یک
 * سه‌تایی می‌تواند موجودی داشته باشد بدون قیمت.
 */
class FactoryStockCleanupTest extends TestCase
{
    use DatabaseTransactions;
    use GrantsAdminPermissions;

    private function approvedSupplier(): User
    {
        $role = Role::firstOrCreate(['name' => 'supplier'], ['display_name' => 'Supplier']);
        $user = User::factory()->create();
        $user->addRole($role);

        $profile = SupplierProfile::create([SupplierProfile::USER_ID => $user->id]);
        $profile->forceFill([SupplierProfile::STATUS => SupplierProfile::STATUS_APPROVED])->save();

        return $user;
    }

    private function factoryFor(User $supplier): Factory
    {
        return Factory::create([
            Factory::SUPPLIER_USER_ID => $supplier->id,
            Factory::NAME => 'کارخانه '.Str::random(4),
            Factory::SLUG => 'f-'.Str::random(10),
            Factory::IS_ACTIVE => true,
        ]);
    }

    private function locationFor(Factory $factory): FactoryLocation
    {
        return FactoryLocation::create([
            FactoryLocation::FACTORY_ID => $factory->id,
            FactoryLocation::NAME => 'انبار '.Str::random(4),
            FactoryLocation::TYPE => 'warehouse',
        ]);
    }

    private function variant(float $globalStock = 0): ProductVariant
    {
        $category = ProductCategory::create([
            'name' => 'دسته تست', 'slug' => 'cat-'.Str::random(8), 'code' => 'C_'.Str::random(6),
        ]);
        $product = Product::create([
            'cuid' => Str::random(20), 'category_id' => $category->id,
            'name' => 'میلگرد تست', 'slug' => 'p-'.Str::random(10), 'sku' => 'SKU_'.Str::random(6),
        ]);

        return ProductVariant::create([
            'product_id' => $product->id, 'name' => 'واریانت تست',
            'sku' => 'V_'.Str::random(6), 'stock_quantity' => $globalStock,
        ]);
    }

    private function stock(ProductVariant $v, Factory $f, FactoryLocation $l, float $qty): FactoryStock
    {
        return FactoryStock::create([
            FactoryStock::PRODUCT_VARIANT_ID => $v->id,
            FactoryStock::FACTORY_ID => $f->id,
            FactoryStock::FACTORY_LOCATION_ID => $l->id,
            FactoryStock::QUANTITY => $qty,
        ]);
    }

    #[Test]
    public function deleting_a_location_rebuilds_the_variant_rollup(): void
    {
        $user = $this->approvedSupplier();
        $factory = $this->factoryFor($user);
        $locA = $this->locationFor($factory);
        $locB = $this->locationFor($factory);
        $variant = $this->variant();

        $this->stock($variant, $factory, $locA, 100);
        $this->stock($variant, $factory, $locB, 50);
        FactoryStock::syncVariantRollup($variant->id);

        $this->assertSame(150.0, (float) $variant->fresh()->stock_quantity);

        Sanctum::actingAs($user);
        $this->deleteJson("/api/profile/supplier/factory-locations/{$locB->id}")->assertOk();

        $this->assertSame(
            0,
            FactoryStock::where(FactoryStock::FACTORY_LOCATION_ID, $locB->id)->count(),
            'ردیف‌های موجودیِ انبارِ حذف‌شده باید رفته باشند.'
        );
        $this->assertSame(
            100.0,
            (float) $variant->fresh()->stock_quantity,
            'رول‌آپ باید برابرِ جمعِ مبادیِ باقی‌مانده شود، نه عددِ متورمِ قبلی.'
        );
    }

    #[Test]
    public function losing_the_last_origin_leaves_the_legacy_stock_alone(): void
    {
        // «هیچ ردیفی نیست» یعنی برگشت به موجودیِ سراسری، نه «همه‌جا تمام شد» —
        // `availableAt()` روی همین قرارداد حساب می‌کند.
        $user = $this->approvedSupplier();
        $factory = $this->factoryFor($user);
        $loc = $this->locationFor($factory);
        $variant = $this->variant(70);

        $this->stock($variant, $factory, $loc, 10);
        FactoryStock::syncVariantRollup($variant->id);
        $this->assertSame(10.0, (float) $variant->fresh()->stock_quantity);

        Sanctum::actingAs($user);
        $this->deleteJson("/api/profile/supplier/factory-locations/{$loc->id}")->assertOk();

        $this->assertSame(
            10.0,
            (float) $variant->fresh()->stock_quantity,
            'واریانتِ بدونِ هیچ مبدأیی نباید صفر شود — عدد قبلی می‌ماند و fallback عمل می‌کند.'
        );
    }

    #[Test]
    public function the_admin_panel_rebuilds_the_rollup_too(): void
    {
        $admin = User::factory()->create();
        $admin->addRole(Role::firstOrCreate(['name' => 'admin'], ['display_name' => 'Admin']));
        // `EnsureAdminHasPermission` از نسخهٔ fail-closed به بعد، ادمینِ بدون
        // مجوز را ۴۰۳ می‌کند — این روت زیر `manage-catalog` است.
        $this->grantAllAdminPermissions($admin);

        $supplier = $this->approvedSupplier();
        $factory = $this->factoryFor($supplier);
        $locA = $this->locationFor($factory);
        $locB = $this->locationFor($factory);
        $variant = $this->variant();

        $this->stock($variant, $factory, $locA, 80);
        $this->stock($variant, $factory, $locB, 20);
        FactoryStock::syncVariantRollup($variant->id);
        $this->assertSame(100.0, (float) $variant->fresh()->stock_quantity);

        Sanctum::actingAs($admin);
        $this->deleteJson("/api/admin/factory-locations/{$locB->id}")->assertOk();

        $this->assertSame(80.0, (float) $variant->fresh()->stock_quantity);
    }
}
