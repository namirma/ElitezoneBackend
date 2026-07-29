<?php

namespace Tests\Feature\Supplier;

use App\Models\Factory;
use App\Models\FactoryLocation;
use App\Models\FactoryStock;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\InventoryService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Laratrust\Models\Role;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * موجودی به‌ازای مبدأ — نقصی که ساختِ پنلِ موجودیِ تأمین‌کننده را بلوکه کرده بود.
 *
 * `product_variants.stock_quantity` یک ستونِ **سراسری برای هر واریانت** بود، در
 * حالی که قیمت به سه‌تاییِ (واریانت × کارخانه × انبار) گره خورده. یعنی دو
 * تأمین‌کننده‌ای که یک واریانت را از دو کارخانه می‌فروختند یک عددِ مشترک داشتند:
 * فروشِ A از موجودیِ B کم می‌کرد، و دادنِ حقِ نوشتن به A یعنی اجازهٔ صفر کردنِ B.
 *
 * تستِ محوریِ این فایل `a_sale_from_one_supplier_leaves_the_other_untouched` است؛
 * بقیه گاردهای اطرافش‌اند.
 */
class PerFactoryStockTest extends TestCase
{
    use DatabaseTransactions;

    private function supplier(): User
    {
        $u = User::factory()->create();
        $u->addRole(Role::firstOrCreate(['name' => 'supplier'], ['display_name' => 'Supplier']));

        return $u;
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
            'name' => 'دسته تست',
            'slug' => 'cat-'.Str::random(8),
            'code' => 'C_'.Str::random(6),
        ]);
        $product = Product::create([
            'cuid' => Str::random(20),
            'category_id' => $category->id,
            'name' => 'میلگرد تست',
            'slug' => 'p-'.Str::random(10),
            'sku' => 'SKU_'.Str::random(6),
        ]);

        return ProductVariant::create([
            'product_id' => $product->id,
            'name' => 'واریانت تست',
            'sku' => 'V_'.Str::random(6),
            'stock_quantity' => $globalStock,
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

    private function paidOrderFrom(User $buyer, ProductVariant $v, Factory $f, FactoryLocation $l, float $weight): Order
    {
        $order = Order::create([
            Order::PLACER_USER_ID => $buyer->id,
            Order::ORDER_NUMBER => 'STK-'.Str::random(10),
            Order::STATUS => Order::STATUS_PROCESSING,
            Order::FINAL_AMOUNT => 1_000_000,
            Order::PAYMENT_STATUS => Order::PAYMENT_PAID,
        ]);

        OrderItem::factory()->create([
            OrderItem::ORDER_ID => $order->id,
            OrderItem::PRODUCT_VARIANT_ID => $v->id,
            OrderItem::FACTORY_ID => $f->id,
            OrderItem::FACTORY_LOCATION_ID => $l->id,
            OrderItem::SUPPLIER_USER_ID => $f->{Factory::SUPPLIER_USER_ID},
            OrderItem::WEIGHT => $weight,
        ]);

        return $order->fresh();
    }

    // ── the defect this whole change exists for ─────────────────────────────

    #[Test]
    public function a_sale_from_one_supplier_leaves_the_other_untouched(): void
    {
        $supplierA = $this->supplier();
        $supplierB = $this->supplier();

        $factoryA = $this->factoryFor($supplierA);
        $factoryB = $this->factoryFor($supplierB);
        $locA = $this->locationFor($factoryA);
        $locB = $this->locationFor($factoryB);

        // یک واریانت، دو کارخانه — سناریوی کاملاً عادی در این بازار.
        $variant = $this->variant();
        $stockA = $this->stock($variant, $factoryA, $locA, 1000);
        $stockB = $this->stock($variant, $factoryB, $locB, 1000);

        $order = $this->paidOrderFrom(User::factory()->create(), $variant, $factoryA, $locA, 300);

        app(InventoryService::class)->deductStockForOrder($order);

        $this->assertSame(700.0, (float) $stockA->fresh()->{FactoryStock::QUANTITY}, 'موجودی A باید ۳۰۰ کم شود.');
        $this->assertSame(1000.0, (float) $stockB->fresh()->{FactoryStock::QUANTITY}, 'موجودی B نباید دست بخورد — همان باگی که این تغییر برای آن است.');
    }

    #[Test]
    public function the_variant_column_becomes_the_sum_of_its_origins(): void
    {
        // ستون هنوز خوانده می‌شود (فیلتر کاتالوگ، تحلیل‌ها). اگر به‌جای جمع صرفاً
        // با هر فروش کم می‌شد، پس از فروشِ کلِ استخرِ قدیمی صفر می‌شد و محصولی را
        // که واقعاً موجود است از کاتالوگ حذف می‌کرد.
        $s = $this->supplier();
        $f = $this->factoryFor($s);
        $l1 = $this->locationFor($f);
        $l2 = $this->locationFor($f);
        $variant = $this->variant();

        $this->stock($variant, $f, $l1, 400);
        $this->stock($variant, $f, $l2, 250);

        FactoryStock::syncVariantRollup($variant->id);

        $this->assertSame(650.0, (float) $variant->fresh()->stock_quantity);
    }

    #[Test]
    public function a_variant_with_no_origin_rows_keeps_its_legacy_stock(): void
    {
        // جمعِ «هیچ ردیفی نیست» با «همه‌جا تمام شده» یکی نیست؛ اولی نباید موجودیِ
        // لگاسیِ سالم را صفر کند.
        $variant = $this->variant(500);

        FactoryStock::syncVariantRollup($variant->id);

        $this->assertSame(500.0, (float) $variant->fresh()->stock_quantity);
    }

    // ── availability + fallback ─────────────────────────────────────────────

    #[Test]
    public function availability_is_read_from_the_origin_not_the_global_column(): void
    {
        $s = $this->supplier();
        $f = $this->factoryFor($s);
        $l = $this->locationFor($f);
        $variant = $this->variant(9999);          // عددِ سراسریِ گمراه‌کننده
        $this->stock($variant, $f, $l, 50);

        $service = app(InventoryService::class);

        $this->assertSame(50.0, $service->availableAt($variant, $f->id, $l->id));
        $this->assertFalse($service->checkStock($variant, 80, $f->id, $l->id));
        $this->assertTrue($service->checkStock($variant, 40, $f->id, $l->id));
    }

    #[Test]
    public function an_origin_with_no_row_falls_back_to_the_legacy_column_rather_than_blocking_sales(): void
    {
        // سه‌تاییِ قیمت‌داری که بعد از backfill اضافه شده هنوز ردیف موجودی ندارد.
        // اگر «نبودِ ردیف» را صفر می‌گرفتیم، بی‌صدا غیرقابل‌فروش می‌شد.
        $s = $this->supplier();
        $f = $this->factoryFor($s);
        $l = $this->locationFor($f);
        $variant = $this->variant(120);

        $service = app(InventoryService::class);

        $this->assertSame(120.0, $service->availableAt($variant, $f->id, $l->id));
        $this->assertTrue($service->checkStock($variant, 100, $f->id, $l->id));
        $this->assertFalse($service->checkStock($variant, 130, $f->id, $l->id));
    }

    // ── restore + weighbridge return to the same origin ─────────────────────

    #[Test]
    public function cancelling_returns_the_goods_to_the_origin_they_left(): void
    {
        $s = $this->supplier();
        $f = $this->factoryFor($s);
        $l = $this->locationFor($f);
        $variant = $this->variant();
        $stock = $this->stock($variant, $f, $l, 1000);

        $order = $this->paidOrderFrom(User::factory()->create(), $variant, $f, $l, 250);
        $service = app(InventoryService::class);

        $service->deductStockForOrder($order);
        $this->assertSame(750.0, (float) $stock->fresh()->{FactoryStock::QUANTITY});

        $service->restoreStock($order);
        $this->assertSame(1000.0, (float) $stock->fresh()->{FactoryStock::QUANTITY});
    }

    #[Test]
    public function a_weighbridge_shortfall_is_taken_from_the_shipping_origin(): void
    {
        $supplierA = $this->supplier();
        $supplierB = $this->supplier();
        $fA = $this->factoryFor($supplierA);
        $fB = $this->factoryFor($supplierB);
        $lA = $this->locationFor($fA);
        $lB = $this->locationFor($fB);

        $variant = $this->variant();
        $stockA = $this->stock($variant, $fA, $lA, 1000);
        $stockB = $this->stock($variant, $fB, $lB, 1000);

        $order = $this->paidOrderFrom(User::factory()->create(), $variant, $fA, $lA, 500);

        // بار ۲۰ کیلو کم بود.
        app(InventoryService::class)->updateStockAfterWeighbridgeForOrder($order, 20);

        $this->assertSame(980.0, (float) $stockA->fresh()->{FactoryStock::QUANTITY});
        $this->assertSame(1000.0, (float) $stockB->fresh()->{FactoryStock::QUANTITY});
    }

    #[Test]
    public function a_weighbridge_correction_never_drives_an_origin_negative(): void
    {
        $s = $this->supplier();
        $f = $this->factoryFor($s);
        $l = $this->locationFor($f);
        $variant = $this->variant();
        $stock = $this->stock($variant, $f, $l, 30);

        $order = $this->paidOrderFrom(User::factory()->create(), $variant, $f, $l, 500);

        app(InventoryService::class)->updateStockAfterWeighbridgeForOrder($order, 900);

        $this->assertSame(0.0, (float) $stock->fresh()->{FactoryStock::QUANTITY});
    }

    // ── supplier panel ──────────────────────────────────────────────────────

    #[Test]
    public function a_non_supplier_cannot_reach_the_stock_panel(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/profile/supplier/stocks')->assertStatus(403);
    }

    #[Test]
    public function a_supplier_only_sees_their_own_origins(): void
    {
        $a = $this->supplier();
        $b = $this->supplier();
        $fa = $this->factoryFor($a);
        $fb = $this->factoryFor($b);
        $variant = $this->variant();

        $mine = $this->stock($variant, $fa, $this->locationFor($fa), 100);
        $theirs = $this->stock($variant, $fb, $this->locationFor($fb), 100);

        Sanctum::actingAs($a);

        $ids = collect($this->getJson('/api/profile/supplier/stocks')->assertOk()->json('data'))->pluck('id');

        $this->assertTrue($ids->contains($mine->id));
        $this->assertFalse($ids->contains($theirs->id));
    }

    #[Test]
    public function a_supplier_can_set_their_own_stock_and_it_upserts(): void
    {
        $s = $this->supplier();
        $f = $this->factoryFor($s);
        $l = $this->locationFor($f);
        $variant = $this->variant();

        Sanctum::actingAs($s);

        $payload = [
            FactoryStock::PRODUCT_VARIANT_ID => $variant->id,
            FactoryStock::FACTORY_ID => $f->id,
            FactoryStock::FACTORY_LOCATION_ID => $l->id,
            FactoryStock::QUANTITY => 750,
        ];

        $this->postJson('/api/profile/supplier/stocks', $payload)->assertOk();

        // بار دوم باید همان ردیف را به‌روز کند، نه ۴۲۲ بدهد.
        // array_merge نه `+`: عملگرِ اجتماعِ آرایه مقدارِ سمتِ چپ را نگه می‌دارد.
        $second = $this->postJson(
            '/api/profile/supplier/stocks',
            array_merge($payload, [FactoryStock::QUANTITY => 900])
        )->assertOk();

        $this->assertSame(900.0, (float) $second->json('data.quantity'));
        $this->assertSame(1, FactoryStock::where(FactoryStock::PRODUCT_VARIANT_ID, $variant->id)->count());
        $this->assertSame(900.0, (float) $variant->fresh()->stock_quantity, 'جمعِ نمایشی باید هم‌گام شود.');
    }

    #[Test]
    public function a_supplier_cannot_write_stock_against_another_suppliers_factory(): void
    {
        $a = $this->supplier();
        $b = $this->supplier();
        $fb = $this->factoryFor($b);
        $lb = $this->locationFor($fb);
        $variant = $this->variant();

        Sanctum::actingAs($a);

        $this->postJson('/api/profile/supplier/stocks', [
            FactoryStock::PRODUCT_VARIANT_ID => $variant->id,
            FactoryStock::FACTORY_ID => $fb->id,
            FactoryStock::FACTORY_LOCATION_ID => $lb->id,
            FactoryStock::QUANTITY => 0,
        ])->assertStatus(422);

        $this->assertSame(0, FactoryStock::count());
    }

    #[Test]
    public function a_supplier_cannot_update_another_suppliers_stock_row(): void
    {
        $a = $this->supplier();
        $b = $this->supplier();
        $fb = $this->factoryFor($b);
        $theirs = $this->stock($this->variant(), $fb, $this->locationFor($fb), 100);

        Sanctum::actingAs($a);

        $this->putJson("/api/profile/supplier/stocks/{$theirs->id}", [FactoryStock::QUANTITY => 0])
            ->assertStatus(403);

        $this->assertSame(100.0, (float) $theirs->fresh()->{FactoryStock::QUANTITY});
    }

    #[Test]
    public function a_supplier_cannot_delete_another_suppliers_stock_row(): void
    {
        $a = $this->supplier();
        $b = $this->supplier();
        $fb = $this->factoryFor($b);
        $theirs = $this->stock($this->variant(), $fb, $this->locationFor($fb), 100);

        Sanctum::actingAs($a);

        $this->deleteJson("/api/profile/supplier/stocks/{$theirs->id}")->assertStatus(403);
        $this->assertDatabaseHas('factory_stocks', ['id' => $theirs->id]);
    }

    #[Test]
    public function the_location_must_belong_to_the_chosen_factory(): void
    {
        // یک تأمین‌کننده با دو کارخانه نباید بتواند موجودیِ کارخانهٔ A را زیر
        // انبارِ کارخانهٔ B ثبت کند — همان گاردی که برای قیمت وجود دارد.
        $s = $this->supplier();
        $f1 = $this->factoryFor($s);
        $f2 = $this->factoryFor($s);
        $l2 = $this->locationFor($f2);

        Sanctum::actingAs($s);

        $this->postJson('/api/profile/supplier/stocks', [
            FactoryStock::PRODUCT_VARIANT_ID => $this->variant()->id,
            FactoryStock::FACTORY_ID => $f1->id,
            FactoryStock::FACTORY_LOCATION_ID => $l2->id,
            FactoryStock::QUANTITY => 10,
        ])->assertStatus(422);
    }
}
