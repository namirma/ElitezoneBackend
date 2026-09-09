<?php

namespace Tests\Feature\Supplier;

use App\Models\Factory;
use App\Models\FactoryLocation;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductPrice;
use App\Models\ProductVariant;
use App\Models\SupplierRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Laratrust\Models\Role;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\GrantsAdminPermissions;
use Tests\TestCase;

/**
 * Self-service supplier product-price management (/profile/supplier/prices).
 *
 * Pins the same isolation guarantee as the factory/location panel, plus the
 * extra cross-field check that is unique to prices: factory_id and
 * factory_location_id must both belong to the caller AND be consistent with
 * each other (the location must actually sit under that factory) — a supplier
 * who owns two factories must not be able to price factory A's catalogue
 * against factory B's warehouse.
 */
class SupplierProductPriceManagementTest extends TestCase
{
    use DatabaseTransactions;
    use GrantsAdminPermissions;

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

    private function makeVariant(): ProductVariant
    {
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

        return ProductVariant::create([
            'product_id' => $product->id,
            'name' => 'واریانت تست',
            'sku' => 'VSKU_'.Str::random(6),
        ]);
    }

    private function priceRow(int $variantId, Factory $factory, FactoryLocation $location, array $overrides = []): array
    {
        return array_merge([
            ProductPrice::PRODUCT_VARIANT_ID => $variantId,
            ProductPrice::FACTORY_ID => $factory->id,
            ProductPrice::FACTORY_LOCATION_ID => $location->id,
            ProductPrice::GENERAL_PRICE => 1_000_000,
            ProductPrice::SPECIAL_PRICE => 900_000,
            ProductPrice::GOLDEN_FIGURE => 950_000,
        ], $overrides);
    }

    // ── role.supplier middleware gate ──────────────────────────────────

    #[Test]
    public function a_non_supplier_cannot_list_prices(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/profile/supplier/prices')->assertStatus(403);
    }

    // ── Create ──────────────────────────────────────────────────────────

    #[Test]
    public function a_supplier_can_set_a_price_for_their_own_factory_and_location(): void
    {
        $supplier = $this->makeSupplier();
        $factory = $this->makeSupplierFactory($supplier);
        $location = $this->makeLocation($factory);
        $variant = $this->makeVariant();

        Sanctum::actingAs($supplier);

        $this->postJson('/api/profile/supplier/prices', $this->priceRow($variant->id, $factory, $location))
            ->assertCreated();

        $this->assertDatabaseHas('product_prices', [
            ProductPrice::PRODUCT_VARIANT_ID => $variant->id,
            ProductPrice::FACTORY_ID => $factory->id,
            ProductPrice::FACTORY_LOCATION_ID => $location->id,
        ]);
    }

    #[Test]
    public function a_supplier_cannot_set_a_price_using_anothers_factory(): void
    {
        $supplierA = $this->makeSupplier();
        $supplierB = $this->makeSupplier();
        $theirFactory = $this->makeSupplierFactory($supplierB);
        $theirLocation = $this->makeLocation($theirFactory);
        $variant = $this->makeVariant();

        Sanctum::actingAs($supplierA);

        $this->postJson('/api/profile/supplier/prices', $this->priceRow($variant->id, $theirFactory, $theirLocation))
            ->assertStatus(422);

        $this->assertDatabaseMissing('product_prices', [ProductPrice::FACTORY_ID => $theirFactory->id]);
    }

    #[Test]
    public function a_supplier_cannot_mix_one_owned_factory_with_a_locations_from_another_owned_factory(): void
    {
        $supplier = $this->makeSupplier();
        $factoryA = $this->makeSupplierFactory($supplier);
        $factoryB = $this->makeSupplierFactory($supplier);
        $locationUnderB = $this->makeLocation($factoryB);
        $variant = $this->makeVariant();

        Sanctum::actingAs($supplier);

        // Both factories are mine, but this location belongs to B, not A — must be rejected.
        $this->postJson('/api/profile/supplier/prices', $this->priceRow($variant->id, $factoryA, $locationUnderB))
            ->assertStatus(422);

        $this->assertDatabaseMissing('product_prices', [ProductPrice::PRODUCT_VARIANT_ID => $variant->id]);
    }

    #[Test]
    public function a_duplicate_price_triple_is_rejected(): void
    {
        $supplier = $this->makeSupplier();
        $factory = $this->makeSupplierFactory($supplier);
        $location = $this->makeLocation($factory);
        $variant = $this->makeVariant();

        ProductPrice::create($this->priceRow($variant->id, $factory, $location));

        Sanctum::actingAs($supplier);

        $this->postJson('/api/profile/supplier/prices', $this->priceRow($variant->id, $factory, $location))
            ->assertStatus(422);
    }

    // ── Isolation ───────────────────────────────────────────────────────

    #[Test]
    public function a_supplier_only_sees_prices_for_their_own_factories(): void
    {
        $supplierA = $this->makeSupplier();
        $supplierB = $this->makeSupplier();

        $factoryA = $this->makeSupplierFactory($supplierA);
        $locationA = $this->makeLocation($factoryA);
        $factoryB = $this->makeSupplierFactory($supplierB);
        $locationB = $this->makeLocation($factoryB);
        $variant = $this->makeVariant();

        $mine = ProductPrice::create($this->priceRow($variant->id, $factoryA, $locationA));
        $theirs = ProductPrice::create($this->priceRow(
            $this->makeVariant()->id,
            $factoryB,
            $locationB,
        ));

        Sanctum::actingAs($supplierA);

        $ids = collect($this->getJson('/api/profile/supplier/prices')->assertOk()->json('data'))
            ->pluck('id');

        $this->assertTrue($ids->contains($mine->id));
        $this->assertFalse($ids->contains($theirs->id));
    }

    #[Test]
    public function a_supplier_cannot_update_anothers_price(): void
    {
        $supplierA = $this->makeSupplier();
        $supplierB = $this->makeSupplier();
        $theirFactory = $this->makeSupplierFactory($supplierB);
        $theirLocation = $this->makeLocation($theirFactory);
        $theirPrice = ProductPrice::create($this->priceRow($this->makeVariant()->id, $theirFactory, $theirLocation));

        Sanctum::actingAs($supplierA);

        $this->putJson("/api/profile/supplier/prices/{$theirPrice->id}", [ProductPrice::GENERAL_PRICE => 1])
            ->assertStatus(403);

        $this->assertDatabaseHas('product_prices', [ProductPrice::ID => $theirPrice->id, ProductPrice::GENERAL_PRICE => 1_000_000]);
    }

    #[Test]
    public function a_supplier_cannot_delete_anothers_price(): void
    {
        $supplierA = $this->makeSupplier();
        $supplierB = $this->makeSupplier();
        $theirFactory = $this->makeSupplierFactory($supplierB);
        $theirLocation = $this->makeLocation($theirFactory);
        $theirPrice = ProductPrice::create($this->priceRow($this->makeVariant()->id, $theirFactory, $theirLocation));

        Sanctum::actingAs($supplierA);

        $this->deleteJson("/api/profile/supplier/prices/{$theirPrice->id}")->assertStatus(403);
        $this->assertDatabaseHas('product_prices', [ProductPrice::ID => $theirPrice->id]);
    }

    // ── Update ──────────────────────────────────────────────────────────

    /**
     * ⚠️ این مورد قبلاً ۱٬۰۰۰٬۰۰۰ → ۱٬۲۰۰٬۰۰۰ می‌فرستاد، یعنی ۲۰٪ — که با آمدنِ
     * دروازه‌ی تأیید (آستانه‌ی پیش‌فرض ۱۵٪) دیگر فوراً اعمال نمی‌شود.
     *
     * عدد **کم شد، ادعا نه**: چیزی که این تست از اول ثابت می‌کرد «تأمین‌کننده به
     * ردیفِ خودش دسترسیِ نوشتن دارد» بود، نه «۲۰٪ مشخصاً». مسیرِ بالای آستانه
     * موردِ جداگانه‌ی خودش را دارد، درست پایین.
     */
    #[Test]
    public function a_supplier_can_update_their_own_price(): void
    {
        $supplier = $this->makeSupplier();
        $factory = $this->makeSupplierFactory($supplier);
        $location = $this->makeLocation($factory);
        $price = ProductPrice::create($this->priceRow($this->makeVariant()->id, $factory, $location));

        Sanctum::actingAs($supplier);

        $this->putJson("/api/profile/supplier/prices/{$price->id}", [
            ProductPrice::GENERAL_PRICE => 1_050_000,   // ۵٪ — زیرِ آستانه
        ])->assertOk();

        $this->assertDatabaseHas('product_prices', [
            ProductPrice::ID => $price->id,
            ProductPrice::GENERAL_PRICE => 1_050_000,
        ]);
    }

    /**
     * پاسخ ۲۰۰ است، پس `assertOk()` تنها چیزی را ثابت نمی‌کند — قیمتِ **دست‌نخورده**
     * چیزی است که اهمیت دارد. اگر دروازه بی‌صدا باز شود، تنها همین assert قرمز
     * می‌شود.
     */
    #[Test]
    public function a_large_price_change_is_queued_instead_of_applied(): void
    {
        $supplier = $this->makeSupplier();
        $factory = $this->makeSupplierFactory($supplier);
        $location = $this->makeLocation($factory);
        $price = ProductPrice::create($this->priceRow($this->makeVariant()->id, $factory, $location));

        Sanctum::actingAs($supplier);

        $this->putJson("/api/profile/supplier/prices/{$price->id}", [
            ProductPrice::GENERAL_PRICE => 1_400_000,   // ۴۰٪ — بالای آستانه
        ])->assertOk()->assertJsonPath('data.queued_for_approval', true);

        $this->assertDatabaseHas('product_prices', [
            ProductPrice::ID => $price->id,
            ProductPrice::GENERAL_PRICE => 1_000_000,
        ]);

        $this->assertDatabaseHas('supplier_requests', [
            SupplierRequest::SUPPLIER_USER_ID => $supplier->id,
            SupplierRequest::TYPE => SupplierRequest::TYPE_PRICE_CHANGE,
            SupplierRequest::STATUS => SupplierRequest::STATUS_PENDING,
        ]);
    }

    /**
     * ⚠️ **راهِ سالامی بسته است** — دلیلِ اصلیِ اینکه مدیریت «آخرین قیمتِ
     * تأییدشده» را انتخاب کرد و «قیمتِ فعلی» را رد کرد.
     *
     * دو قدمِ ۱۰ درصدیِ پشتِ سرِ هم — هر کدام نسبت به **قیمتِ فعلی** زیرِ آستانه‌ی
     * ۱۵٪ است، ولی روی هم ۲۱٪ می‌شود. با مبنای «قیمت فعلی» هر دو فوراً اعمال
     * می‌شدند و قیمت ۲۱٪ بالا می‌رفت بی‌آنکه یک بار به صفِ تأیید بخورد.
     *
     * ⚠️ نسخه‌ی اولِ این تست سه قدم داشت و انتظار داشت سومی بگیرد — غلط بود.
     * ریاضی می‌گوید **قدمِ دوم** می‌گیرد (۱٬۲۱۰٬۰۰۰ یعنی ۲۱٪ نسبت به ۱٬۰۰۰٬۰۰۰).
     * تست اصلاح شد تا رفتارِ واقعی را پین کند، نه انتظارِ اولیه را.
     */
    #[Test]
    public function repeated_small_changes_cannot_walk_past_the_threshold(): void
    {
        $supplier = $this->makeSupplier();
        $factory = $this->makeSupplierFactory($supplier);
        $location = $this->makeLocation($factory);
        $price = ProductPrice::create($this->priceRow($this->makeVariant()->id, $factory, $location));

        Sanctum::actingAs($supplier);

        // قدمِ اول: ۱۰٪ نسبت به مبنا — فوراً اعمال می‌شود.
        $this->putJson("/api/profile/supplier/prices/{$price->id}", [
            ProductPrice::GENERAL_PRICE => 1_100_000,
        ])->assertOk()->assertJsonMissingPath('data.queued_for_approval');

        $this->assertSame(1_100_000.0, (float) $price->refresh()->{ProductPrice::GENERAL_PRICE});

        // قدمِ دوم: باز هم ۱۰٪ نسبت به قیمتِ فعلی — ولی ۲۱٪ نسبت به مبنا. می‌گیرد.
        $this->putJson("/api/profile/supplier/prices/{$price->id}", [
            ProductPrice::GENERAL_PRICE => 1_210_000,
        ])->assertOk()->assertJsonPath('data.queued_for_approval', true);

        $this->assertSame(
            1_100_000.0,
            (float) $price->refresh()->{ProductPrice::GENERAL_PRICE},
            'قدمِ دوم باید به صف برود، نه اینکه اعمال شود.'
        );

        $this->assertSame(
            1_000_000.0,
            (float) $price->{ProductPrice::APPROVED_GENERAL_PRICE},
            'مبنا با تغییرِ زیرِ آستانه تکان نمی‌خورد — همین راهِ سالامی را می‌بندد.'
        );
    }

    /** تأییدِ ادمین مبنا را جلو می‌برد، وگرنه هر تغییرِ بعدی بی‌دلیل به صف می‌رود. */
    #[Test]
    public function approval_moves_the_baseline_forward(): void
    {
        $supplier = $this->makeSupplier();
        $factory = $this->makeSupplierFactory($supplier);
        $location = $this->makeLocation($factory);
        $price = ProductPrice::create($this->priceRow($this->makeVariant()->id, $factory, $location));

        Sanctum::actingAs($supplier);
        $this->putJson("/api/profile/supplier/prices/{$price->id}", [
            ProductPrice::GENERAL_PRICE => 1_400_000,   // ۴۰٪ — به صف
        ])->assertOk();

        $request = SupplierRequest::query()
            ->where(SupplierRequest::TYPE, SupplierRequest::TYPE_PRICE_CHANGE)
            ->latest('id')->firstOrFail();

        $admin = User::factory()->create();
        $admin->addRole(Role::firstOrCreate(['name' => 'admin'], ['display_name' => 'Admin']));
        $this->grantAllAdminPermissions($admin);
        Sanctum::actingAs($admin);

        $this->postJson("/api/admin/supplier-requests/{$request->id}/approve")->assertOk();

        $price->refresh();
        $this->assertSame(1_400_000.0, (float) $price->{ProductPrice::GENERAL_PRICE});
        $this->assertSame(
            1_400_000.0,
            (float) $price->{ProductPrice::APPROVED_GENERAL_PRICE},
            'مبنا باید همراهِ قیمت جلو برود.'
        );

        // و حالا یک تغییرِ کوچک نسبت به عددِ تازه باید فوراً اعمال شود.
        Sanctum::actingAs($supplier);
        $this->putJson("/api/profile/supplier/prices/{$price->id}", [
            ProductPrice::GENERAL_PRICE => 1_450_000,   // ۳٫۶٪ نسبت به مبنای تازه
        ])->assertOk()->assertJsonMissingPath('data.queued_for_approval');
    }

    /**
     * ⚠️ نیمه‌ی دومِ همان داستان: پایه بینِ ثبت و تأیید تکان خورده.
     *
     * بینِ صف‌رفتن و تأییدِ ادمین، قیمتِ پایه می‌تواند عوض شود — با یک تغییرِ
     * زیرِ آستانه‌ی خودِ تأمین‌کننده، یا دستِ ادمینِ دیگر. اگر تأیید کورکورانه
     * `proposed_*` را بنشاند، دو چیز خراب می‌شود: قیمتِ **تازه‌تر** بی‌صدا
     * بازنویسی می‌شود، و درصدی که ادمین دید و بر مبنایش تصمیم گرفت دیگر درست
     * نیست (شاید حالا اصلاً زیرِ آستانه باشد).
     *
     * این‌جا کنارِ موردِ بالا نشسته چون نیمه‌ی دیگرِ همان جریان است و فیکسچرهای
     * کارخانه/انبار/واریانت همین‌جا هستند — یک کپیِ چهل‌خطی در فایلِ دیگر
     * ارزشش را نداشت.
     */
    #[Test]
    public function approval_is_refused_when_the_base_price_moved_since_the_request(): void
    {
        $supplier = $this->makeSupplier();
        $factory = $this->makeSupplierFactory($supplier);
        $location = $this->makeLocation($factory);
        $price = ProductPrice::create($this->priceRow($this->makeVariant()->id, $factory, $location));

        Sanctum::actingAs($supplier);
        $this->putJson("/api/profile/supplier/prices/{$price->id}", [
            ProductPrice::GENERAL_PRICE => 1_400_000,
        ])->assertOk();

        $request = SupplierRequest::query()
            ->where(SupplierRequest::TYPE, SupplierRequest::TYPE_PRICE_CHANGE)
            ->latest('id')->firstOrFail();

        // پایه زیرِ پای درخواست تکان می‌خورد (تغییرِ زیرِ آستانه، پس فوراً اعمال شد).
        $this->putJson("/api/profile/supplier/prices/{$price->id}", [
            ProductPrice::GENERAL_PRICE => 1_050_000,
        ])->assertOk();

        $admin = User::factory()->create();
        $admin->addRole(Role::firstOrCreate(['name' => 'admin'], ['display_name' => 'Admin']));
        $this->grantAllAdminPermissions($admin);
        Sanctum::actingAs($admin);

        $this->postJson("/api/admin/supplier-requests/{$request->id}/approve")
            ->assertStatus(409);

        $this->assertSame(
            1_050_000.0,
            (float) $price->refresh()->{ProductPrice::GENERAL_PRICE},
            'قیمتِ تازه‌تر نباید با مقدارِ کهنه‌ی درخواست بازنویسی شود.'
        );

        $this->assertTrue(
            $request->refresh()->isPending(),
            'تراکنش باید کامل برگردد — درخواست باز می‌ماند تا ادمین با عددِ امروز دوباره تصمیم بگیرد.'
        );
    }
}
