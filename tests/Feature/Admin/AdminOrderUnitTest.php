<?php

namespace Tests\Feature\Admin;

use App\Models\OrderUnit;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laratrust\Models\Role;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\GrantsAdminPermissions;
use Tests\TestCase;

/**
 * واحدهای سفارش — CRUD ادمین + پیوتِ محصول×واحد.
 *
 * چرا این تست‌ها اهمیت دارند: `order_units.amount` ضریب تبدیل به کیلوگرم است و
 * `product_order_units.max_quantity` تنها چیزی است که `AddToCartAction` را از ۴۲۲
 * نگه می‌دارد. تا نشست ۲۱ هیچ‌کدام روت ادمینی نداشتند.
 */
class AdminOrderUnitTest extends TestCase
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

    private function makeUnit(float $amount = 1000, ?string $code = null): OrderUnit
    {
        return OrderUnit::create([
            OrderUnit::NAME => 'واحد '.$this->uid(),
            OrderUnit::CODE => $code ?? 'u_'.$this->uid(),
            OrderUnit::UNIT => 'kg',
            OrderUnit::AMOUNT => $amount,
        ]);
    }

    private function makeProduct(): Product
    {
        $u = $this->uid();
        $category = ProductCategory::create(['name' => "دسته {$u}", 'slug' => "cat-{$u}"]);

        return Product::create([
            Product::CATEGORY_ID => $category->id,
            Product::NAME => "محصول {$u}",
            Product::SLUG => "prod-{$u}",
            Product::SKU => "SKU-{$u}",
        ]);
    }

    // ── Auth gate ─────────────────────────────────────────────────────────

    #[Test]
    public function guest_is_unauthorized_on_order_units(): void
    {
        $this->getJson('/api/admin/order-units')->assertUnauthorized();
    }

    #[Test]
    public function non_admin_is_forbidden_on_order_units(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $this->getJson('/api/admin/order-units')->assertForbidden();
    }

    #[Test]
    public function admin_without_manage_catalog_is_forbidden(): void
    {
        $user = User::factory()->create();
        $user->addRole(Role::firstOrCreate(['name' => 'admin'], ['display_name' => 'Admin']));
        // عمداً هیچ مجوزی داده نمی‌شود — گیت fail-closed است.
        Sanctum::actingAs($user);

        $this->getJson('/api/admin/order-units')->assertForbidden();
    }

    // ── CRUD ──────────────────────────────────────────────────────────────

    #[Test]
    public function admin_can_create_update_and_delete_an_order_unit(): void
    {
        Sanctum::actingAs($this->admin());
        $code = 'u_'.$this->uid();

        $id = $this->postJson('/api/admin/order-units', [
            'name' => 'نیم‌بندیل', 'code' => $code, 'unit' => 'kg', 'amount' => 2500,
        ])->assertCreated()->json('data.id');

        $this->assertDatabaseHas('order_units', ['id' => $id, 'code' => $code, 'amount' => 2500]);

        $this->putJson("/api/admin/order-units/{$id}", ['amount' => 2750])
            ->assertOk()
            ->assertJsonPath('data.amount', '2750.00');

        $this->deleteJson("/api/admin/order-units/{$id}")->assertOk();
        $this->assertDatabaseMissing('order_units', ['id' => $id]);
    }

    #[Test]
    public function order_unit_code_must_be_unique(): void
    {
        Sanctum::actingAs($this->admin());
        $existing = $this->makeUnit();

        $this->postJson('/api/admin/order-units', [
            'name' => 'تکراری', 'code' => $existing->code, 'unit' => 'kg', 'amount' => 10,
        ])->assertStatus(422)->assertJsonValidationErrors('code');
    }

    #[Test]
    public function zero_amount_is_rejected(): void
    {
        // amount صفر یعنی تقسیم‌بر‌صفر در convertQuantitiesToTargetUnitValue و وزنِ
        // صفر در calculatePhysicalAttributes — پس نباید اصلاً ذخیره شود.
        Sanctum::actingAs($this->admin());

        $this->postJson('/api/admin/order-units', [
            'name' => 'صفر', 'code' => 'u_'.$this->uid(), 'unit' => 'kg', 'amount' => 0,
        ])->assertStatus(422)->assertJsonValidationErrors('amount');
    }

    #[Test]
    public function list_reports_how_many_products_use_each_unit(): void
    {
        Sanctum::actingAs($this->admin());
        $unit = $this->makeUnit();
        $product = $this->makeProduct();
        $product->orderUnits()->sync([$unit->id => ['max_quantity' => 7]]);

        $row = collect($this->getJson('/api/admin/order-units?per_page=50')->assertOk()->json('data'))
            ->firstWhere('id', $unit->id);

        $this->assertNotNull($row, 'واحد ساخته‌شده در لیست ادمین نبود.');
        $this->assertSame(1, $row['products_count']);
    }

    // ── Delete guards: هر سه مسیرِ ارجاع ──────────────────────────────────

    #[Test]
    public function cannot_delete_a_unit_attached_to_a_product(): void
    {
        Sanctum::actingAs($this->admin());
        $unit = $this->makeUnit();
        $this->makeProduct()->orderUnits()->sync([$unit->id => ['max_quantity' => 5]]);

        $this->deleteJson("/api/admin/order-units/{$unit->id}")->assertStatus(422);
        $this->assertDatabaseHas('order_units', ['id' => $unit->id]);
    }

    #[Test]
    public function cannot_delete_a_unit_used_as_a_variant_limit(): void
    {
        Sanctum::actingAs($this->admin());
        $unit = $this->makeUnit();
        $product = $this->makeProduct();
        ProductVariant::create([
            ProductVariant::PRODUCT_ID => $product->id,
            ProductVariant::NAME => 'واریانت',
            ProductVariant::SKU => 'V-'.$this->uid(),
            ProductVariant::LIMIT_ORDER_UNIT_ID => $unit->id,
        ]);

        $this->deleteJson("/api/admin/order-units/{$unit->id}")->assertStatus(422);
        $this->assertDatabaseHas('order_units', ['id' => $unit->id]);
    }

    #[Test]
    public function cannot_delete_a_unit_used_as_a_product_aggregate_limit(): void
    {
        Sanctum::actingAs($this->admin());
        $unit = $this->makeUnit();
        $product = $this->makeProduct();
        $product->update([Product::AGGREGATE_LIMIT_ORDER_UNIT_ID => $unit->id]);

        $this->deleteJson("/api/admin/order-units/{$unit->id}")->assertStatus(422);
        $this->assertDatabaseHas('order_units', ['id' => $unit->id]);
    }

    // ── پیوتِ محصول × واحد ────────────────────────────────────────────────

    #[Test]
    public function admin_can_sync_product_order_units(): void
    {
        Sanctum::actingAs($this->admin());
        $product = $this->makeProduct();
        $a = $this->makeUnit(5000);
        $b = $this->makeUnit(1);

        $this->postJson("/api/admin/products/{$product->cuid}/order-units", [
            'units' => [
                ['order_unit_id' => $a->id, 'max_quantity' => 30],
                ['order_unit_id' => $b->id, 'max_quantity' => 2000000],
            ],
        ])->assertOk();

        $this->assertDatabaseHas('product_order_units', [
            'product_id' => $product->id, 'order_unit_id' => $a->id, 'max_quantity' => 30,
        ]);
        $this->assertDatabaseHas('product_order_units', [
            'product_id' => $product->id, 'order_unit_id' => $b->id, 'max_quantity' => 2000000,
        ]);
    }

    #[Test]
    public function sync_replaces_the_whole_set_rather_than_appending(): void
    {
        Sanctum::actingAs($this->admin());
        $product = $this->makeProduct();
        $a = $this->makeUnit(5000);
        $b = $this->makeUnit(1);
        $product->orderUnits()->sync([$a->id => ['max_quantity' => 5]]);

        $this->postJson("/api/admin/products/{$product->cuid}/order-units", [
            'units' => [['order_unit_id' => $b->id, 'max_quantity' => 9]],
        ])->assertOk();

        $this->assertDatabaseMissing('product_order_units', [
            'product_id' => $product->id, 'order_unit_id' => $a->id,
        ]);
        $this->assertDatabaseHas('product_order_units', [
            'product_id' => $product->id, 'order_unit_id' => $b->id, 'max_quantity' => 9,
        ]);
    }

    #[Test]
    public function sync_with_an_empty_array_clears_every_unit(): void
    {
        // «هیچ واحدی» یک حالت معتبر است (محصول موقتاً غیرقابل‌سفارش) و نباید ۴۲۲ شود —
        // `present` به‌جای `required` دقیقاً برای همین است.
        Sanctum::actingAs($this->admin());
        $product = $this->makeProduct();
        $unit = $this->makeUnit();
        $product->orderUnits()->sync([$unit->id => ['max_quantity' => 5]]);

        $this->postJson("/api/admin/products/{$product->cuid}/order-units", ['units' => []])
            ->assertOk();

        $this->assertDatabaseMissing('product_order_units', ['product_id' => $product->id]);
    }

    #[Test]
    public function sync_rejects_an_unknown_order_unit(): void
    {
        Sanctum::actingAs($this->admin());
        $product = $this->makeProduct();

        $this->postJson("/api/admin/products/{$product->cuid}/order-units", [
            'units' => [['order_unit_id' => 99999999, 'max_quantity' => 5]],
        ])->assertStatus(422)->assertJsonValidationErrors('units.0.order_unit_id');
    }

    // ── قرارداد پاسخ که باکس خریدِ سایت به آن وابسته است ──────────────────

    #[Test]
    public function product_resource_exposes_amount_and_max_quantity_together(): void
    {
        // باکس خرید هر دو را لازم دارد: amount برای محاسبهٔ وزن، max_quantity برای سقف
        // انتخاب. پیش از نشست ۲۱ هیچ endpointی هر دو را با هم نمی‌داد و فرانت مجبور
        // بود ۲۵۰۰۰/۵۰۰۰ را هاردکد کند.
        Sanctum::actingAs($this->admin());
        $product = $this->makeProduct();
        $big = $this->makeUnit(5000);
        $base = $this->makeUnit(1);
        $product->orderUnits()->sync([
            $big->id => ['max_quantity' => 30],
            $base->id => ['max_quantity' => 2000000],
        ]);

        $limits = $this->getJson("/api/admin/products/{$product->cuid}")
            ->assertOk()
            ->json('data.order_unit_limits');

        $this->assertCount(2, $limits);
        // ترتیب: بستهٔ بزرگ‌تر اول، واحد پایه آخر — همان ترتیبی که سایت رندر می‌کند.
        $this->assertSame($big->id, $limits[0]['order_unit_id']);
        $this->assertSame($base->id, $limits[1]['order_unit_id']);

        // ⚠️ این دو باید **عدد** باشند نه رشته. ستون‌های decimal لاراول رشته
        // برمی‌گردانند («5000.00») و باکس خرید با همین‌ها ضرب می‌کند؛ رشته یعنی فرانت
        // باید خودش parse کند — دقیقاً همان‌جایی که باگ ۱۰۰ برابرِ نشست ۲۰ متولد شد.
        // Resource عمداً (float) می‌زند. int/float بودنِ نمایشِ JSON مهم نیست، رشته‌نبودن مهم است.
        foreach ([[0, 5000.0, 30.0], [1, 1.0, 2000000.0]] as [$i, $amount, $max]) {
            $this->assertIsNotString($limits[$i]['amount'], 'amount نباید رشته باشد.');
            $this->assertIsNotString($limits[$i]['max_quantity'], 'max_quantity نباید رشته باشد.');
            $this->assertEqualsWithDelta($amount, $limits[$i]['amount'], 0.001);
            $this->assertEqualsWithDelta($max, $limits[$i]['max_quantity'], 0.001);
        }
    }

    #[Test]
    public function amount_has_the_same_shape_after_write_as_after_read(): void
    {
        // بدون refresh()، پاسخِ store/update مقدارِ در-حافظه (همان چیزی که کلاینت
        // فرستاده، مثلاً int 2500) را برمی‌گرداند ولی GET بعدی مقدارِ decimalِ
        // دیتابیس («2500.00») را — یعنی یک فیلد، دو شکل، بسته به اینکه تازه نوشته
        // شده یا نه. پنل بعد از ساخت، لیست را دوباره می‌خواند و همین ناسازگاری
        // باعث می‌شود مقدارِ فرم با مقدارِ جدول فرق کند.
        Sanctum::actingAs($this->admin());

        $created = $this->postJson('/api/admin/order-units', [
            'name' => 'شکل', 'code' => 'u_'.$this->uid(), 'unit' => 'kg', 'amount' => 2500,
        ])->assertCreated();

        $id = $created->json('data.id');
        $fetched = $this->getJson("/api/admin/order-units/{$id}")->assertOk();

        $this->assertSame($fetched->json('data.amount'), $created->json('data.amount'));

        $updated = $this->putJson("/api/admin/order-units/{$id}", ['amount' => 2750])->assertOk();
        $refetched = $this->getJson("/api/admin/order-units/{$id}")->assertOk();

        $this->assertSame($refetched->json('data.amount'), $updated->json('data.amount'));
    }

    #[Test]
    public function product_resource_exposes_aggregate_limits_for_form_prefill(): void
    {
        Sanctum::actingAs($this->admin());
        $unit = $this->makeUnit(1);
        $product = $this->makeProduct();

        $this->putJson("/api/admin/products/{$product->cuid}", [
            'aggregate_min_order_value' => 2000,
            'aggregate_max_order_value' => 5000000,
            'aggregate_limit_order_unit_id' => $unit->id,
        ])->assertOk();

        $this->getJson("/api/admin/products/{$product->cuid}")
            ->assertOk()
            ->assertJsonPath('data.aggregate_limit_order_unit_id', $unit->id)
            ->assertJsonPath('data.aggregate_min_order_value', '2000.00')
            ->assertJsonPath('data.aggregate_max_order_value', '5000000.00');
    }
}
