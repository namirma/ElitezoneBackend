<?php

declare(strict_types=1);

namespace Tests\Feature\Supplier;

use App\Jobs\Notification\SendSmsJob;
use App\Models\Attribute;
use App\Models\AttributeType;
use App\Models\AttributeValue;
use App\Models\Factory;
use App\Models\FactoryLocation;
use App\Models\OrderUnit;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductChangeRequest;
use App\Models\ProductPrice;
use App\Models\ProductVariant;
use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laratrust\Models\Role;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\GrantsAdminPermissions;
use Tests\TestCase;

/**
 * درخواستِ تغییرِ کاتالوگ از سوی تأمین‌کننده — ثبت، مالکیت، تصمیمِ ادمین، اعمال.
 *
 * قاعده‌ای که پین می‌شود: **هیچ پیشنهادی بدونِ تأییدِ ادمین به کاتالوگ نمی‌رسد**،
 * و پذیرش دقیقاً همان payload را از مسیرِ نوشتنِ ادمین اعمال می‌کند.
 */
class ProductChangeRequestTest extends TestCase
{
    use DatabaseTransactions;
    use GrantsAdminPermissions;

    private function uid(): string
    {
        return substr(md5(uniqid('', true)), 0, 8);
    }

    private function supplier(): User
    {
        $user = User::factory()->create();
        $user->addRole(Role::firstOrCreate(['name' => 'supplier'], ['display_name' => 'Supplier']));

        return $user;
    }

    private function admin(): User
    {
        $user = User::factory()->create();
        $user->addRole(Role::firstOrCreate(['name' => 'admin'], ['display_name' => 'Admin']));

        return $this->grantAllAdminPermissions($user);
    }

    private function category(): ProductCategory
    {
        $u = $this->uid();

        return ProductCategory::create(['name' => "دسته {$u}", 'slug' => "cat-{$u}", 'code' => "C_{$u}"]);
    }

    private function unit(): OrderUnit
    {
        $u = $this->uid();

        return OrderUnit::create([OrderUnit::NAME => "واحد {$u}", OrderUnit::CODE => "u_{$u}", OrderUnit::UNIT => 'kg', OrderUnit::AMOUNT => 1000]);
    }

    /** @return array{0: Attribute, 1: AttributeValue} */
    private function attributePair(): array
    {
        $u = $this->uid();
        $type = AttributeType::create([AttributeType::NAME => "type-{$u}", AttributeType::DISPLAY_NAME => "نوع {$u}", AttributeType::INPUT_TYPE => 'select']);
        $attribute = Attribute::create([Attribute::ATTRIBUTE_TYPE_ID => $type->id, Attribute::NAME => "attr-{$u}", Attribute::DISPLAY_NAME => "ویژگی {$u}"]);
        $value = AttributeValue::create([AttributeValue::ATTRIBUTE_ID => $attribute->id, AttributeValue::VALUE => '14', AttributeValue::DISPLAY_VALUE => '۱۴']);

        return [$attribute, $value];
    }

    private function product(?ProductCategory $category = null): Product
    {
        $u = $this->uid();

        return Product::create([
            Product::CATEGORY_ID => ($category ?? $this->category())->id,
            Product::NAME => "محصول {$u}",
            Product::SLUG => "prod-{$u}",
            Product::SKU => "SKU-{$u}",
            Product::DESCRIPTION => 'توضیح قبلی',
        ]);
    }

    private function variant(Product $product): ProductVariant
    {
        $u = $this->uid();

        return ProductVariant::create([
            ProductVariant::PRODUCT_ID => $product->id,
            ProductVariant::NAME => "واریانت {$u}",
            ProductVariant::SKU => "VSKU-{$u}",
        ]);
    }

    /** یک ردیفِ قیمت از کارخانه‌ی این تأمین‌کننده = «این کالا را تأمین می‌کند». */
    private function supply(User $supplier, ProductVariant $variant): void
    {
        $factory = Factory::create([
            Factory::SUPPLIER_USER_ID => $supplier->id,
            Factory::NAME => 'کارخانه',
            Factory::SLUG => 'f-'.Str::random(8),
            Factory::IS_ACTIVE => true,
        ]);
        $location = FactoryLocation::create([FactoryLocation::FACTORY_ID => $factory->id, FactoryLocation::NAME => 'انبار', FactoryLocation::TYPE => 'warehouse']);
        ProductPrice::create([
            ProductPrice::PRODUCT_VARIANT_ID => $variant->id,
            ProductPrice::FACTORY_ID => $factory->id,
            ProductPrice::FACTORY_LOCATION_ID => $location->id,
            ProductPrice::GENERAL_PRICE => 1_000_000,
            ProductPrice::SPECIAL_PRICE => 900_000,
            ProductPrice::GOLDEN_FIGURE => 0,
        ]);
    }

    /** @return array<string, mixed> */
    private function newProductBody(ProductCategory $category, OrderUnit $unit, Attribute $attribute, AttributeValue $value): array
    {
        $u = $this->uid();

        return [
            'name' => "کالای پیشنهادی {$u}",
            'category_id' => $category->id,
            'slug' => "proposed-{$u}",
            'sku' => "PSKU-{$u}",
            'description' => 'توضیح پیشنهادی',
            'items_per_page' => 12,
            'aggregate_limit_order_unit_id' => $unit->id,
            'aggregate_min_order_value' => 2,
            'order_units' => [['order_unit_id' => $unit->id, 'max_quantity' => 50]],
            'variants' => [
                ['name' => 'سایز ۱۲', 'sku' => "V12-{$u}", 'attributes' => [['attribute_id' => $attribute->id, 'attribute_value_id' => $value->id]]],
                ['name' => 'سایز ۱۴', 'sku' => "V14-{$u}", 'min_order_value' => 1, 'limit_order_unit_id' => $unit->id],
            ],
            'note' => 'این کالا را از این ماه تولید می‌کنیم.',
        ];
    }

    // ── دروازه‌ها ─────────────────────────────────────────────────────────

    #[Test]
    public function a_non_supplier_cannot_see_the_product_list(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/profile/supplier/products')->assertForbidden();
    }

    #[Test]
    public function an_admin_without_manage_catalog_cannot_see_the_queue(): void
    {
        $user = User::factory()->create();
        $user->addRole(Role::firstOrCreate(['name' => 'admin'], ['display_name' => 'Admin']));
        Sanctum::actingAs($user);

        $this->getJson('/api/admin/product-change-requests')->assertForbidden();
    }

    // ── فهرست ─────────────────────────────────────────────────────────────

    #[Test]
    public function the_list_holds_only_supplied_products_and_flags_open_requests(): void
    {
        $supplier = $this->supplier();
        $mine = $this->product();
        $this->supply($supplier, $this->variant($mine));
        $this->product(); // کالای دیگری که تأمین نمی‌کند

        ProductChangeRequest::create([
            ProductChangeRequest::SUPPLIER_USER_ID => $supplier->id,
            ProductChangeRequest::TYPE => ProductChangeRequest::TYPE_UPDATE_PRODUCT,
            ProductChangeRequest::PRODUCT_ID => $mine->id,
            ProductChangeRequest::PAYLOAD => ['name' => 'x'],
            ProductChangeRequest::DIFF => ['name' => ['before' => $mine->name, 'after' => 'x']],
        ]);

        Sanctum::actingAs($supplier);

        $res = $this->getJson('/api/profile/supplier/products')->assertOk();

        $this->assertCount(1, $res->json('data'));
        $this->assertSame($mine->cuid, $res->json('data.0.cuid'));
        $this->assertSame(1, $res->json('data.0.pending_change_requests_count'));
        $this->assertSame(1, $res->json('data.0.variants_count'));
    }

    #[Test]
    public function show_returns_the_full_spec_and_open_requests_and_refuses_unsupplied_products(): void
    {
        $supplier = $this->supplier();
        $mine = $this->product();
        $this->supply($supplier, $this->variant($mine));

        Sanctum::actingAs($supplier);

        $this->getJson("/api/profile/supplier/products/{$mine->cuid}")
            ->assertOk()
            ->assertJsonPath('data.product.cuid', $mine->cuid)
            ->assertJsonCount(1, 'data.variants')
            ->assertJsonCount(0, 'data.pending_requests');

        $this->getJson('/api/profile/supplier/products/'.$this->product()->cuid)->assertForbidden();
    }

    #[Test]
    public function options_carry_categories_units_and_attribute_types(): void
    {
        $category = $this->category();
        [$attribute] = $this->attributePair();
        $category->attributeTypes()->attach($attribute->attribute_type_id);
        $this->unit();

        Sanctum::actingAs($this->supplier());

        $res = $this->getJson('/api/profile/supplier/products/options')->assertOk();

        $cat = collect($res->json('data.categories'))->firstWhere('id', $category->id);
        $this->assertSame([$attribute->attribute_type_id], $cat['attribute_type_ids']);
        $this->assertNotEmpty($res->json('data.order_units'));
        $type = collect($res->json('data.attribute_types'))->firstWhere('id', $attribute->attribute_type_id);
        $this->assertSame('14', $type['attributes'][0]['values'][0]['value']);
    }

    // ── ثبتِ پیشنهاد ──────────────────────────────────────────────────────

    #[Test]
    public function a_create_proposal_is_queued_and_touches_nothing_in_the_catalog(): void
    {
        Storage::fake('public');
        $supplier = $this->supplier();
        [$attribute, $value] = $this->attributePair();
        $body = $this->newProductBody($this->category(), $this->unit(), $attribute, $value);

        Sanctum::actingAs($supplier);

        $res = $this->post('/api/profile/supplier/products', $body + [
            'images' => [UploadedFile::fake()->image('a.jpg'), UploadedFile::fake()->image('b.jpg')],
            'primary_image_index' => 1,
        ], ['Accept' => 'application/json'])->assertCreated();

        $this->assertSame(ProductChangeRequest::TYPE_CREATE_PRODUCT, $res->json('data.type'));
        $this->assertSame(ProductChangeRequest::STATUS_PENDING, $res->json('data.status'));
        $this->assertCount(2, $res->json('data.media.images'));
        $this->assertTrue($res->json('data.media.images.1.is_primary'));
        $this->assertFalse($res->json('data.media.images.0.is_primary'));

        $this->assertDatabaseMissing('products', ['slug' => $body['slug']]);

        $stored = ProductChangeRequest::findOrFail($res->json('data.id'));
        $this->assertCount(2, $stored->payload['variants']);
        $this->assertSame($value->id, $stored->payload['variants'][0]['attributes'][0]['attribute_value_id']);
        Storage::disk('public')->assertExists($stored->payload['images'][0]['path']);
        $this->assertStringStartsWith(ProductChangeRequest::UPLOAD_DIR.'/', $stored->payload['images'][0]['path']);
    }

    #[Test]
    public function a_supplier_cannot_propose_changes_to_a_product_they_do_not_supply(): void
    {
        $supplier = $this->supplier();
        $other = $this->product();
        $variant = $this->variant($other);

        Sanctum::actingAs($supplier);

        $this->putJson("/api/profile/supplier/products/{$other->cuid}", ['name' => 'دزدی'])->assertForbidden();
        $this->postJson("/api/profile/supplier/products/{$other->cuid}/variants", ['name' => 'x', 'sku' => 'x-'.$this->uid()])->assertForbidden();
        $this->putJson("/api/profile/supplier/variants/{$variant->id}", ['name' => 'x'])->assertForbidden();

        $this->assertDatabaseCount('product_change_requests', 0);
    }

    #[Test]
    public function an_update_proposal_stores_only_the_changed_fields_as_a_diff(): void
    {
        $supplier = $this->supplier();
        $product = $this->product();
        $this->supply($supplier, $this->variant($product));
        $unit = $this->unit();

        Sanctum::actingAs($supplier);

        $res = $this->putJson("/api/profile/supplier/products/{$product->cuid}", [
            'name' => $product->name,          // بدونِ تغییر
            'description' => 'توضیح تازه',      // تغییر
            'order_units' => [['order_unit_id' => $unit->id, 'max_quantity' => 10]],
        ])->assertCreated();

        $diff = $res->json('data.diff');
        $this->assertArrayNotHasKey('name', $diff);
        $this->assertSame(['before' => 'توضیح قبلی', 'after' => 'توضیح تازه'], $diff['description']);
        $this->assertSame([], $diff['order_units']['before']);
        $this->assertSame($unit->id, $diff['order_units']['after'][0]['order_unit_id']);

        // کاتالوگ دست‌نخورده
        $this->assertSame('توضیح قبلی', $product->fresh()->description);
    }

    #[Test]
    public function an_update_proposal_with_no_changes_is_rejected(): void
    {
        $supplier = $this->supplier();
        $product = $this->product();
        $this->supply($supplier, $this->variant($product));

        Sanctum::actingAs($supplier);

        $this->putJson("/api/profile/supplier/products/{$product->cuid}", ['name' => $product->name])
            ->assertStatus(422)
            ->assertJsonPath('message', __('product_changes.no_changes'));
    }

    #[Test]
    public function a_variant_update_proposal_diffs_attributes_too(): void
    {
        $supplier = $this->supplier();
        $product = $this->product();
        $variant = $this->variant($product);
        $this->supply($supplier, $variant);
        [$attribute, $value] = $this->attributePair();

        Sanctum::actingAs($supplier);

        $res = $this->putJson("/api/profile/supplier/variants/{$variant->id}", [
            'name' => 'نام تازه',
            'attributes' => [['attribute_id' => $attribute->id, 'attribute_value_id' => $value->id]],
        ])->assertCreated();

        $this->assertSame(ProductChangeRequest::TYPE_UPDATE_VARIANT, $res->json('data.type'));
        $this->assertSame($variant->id, $res->json('data.product_variant_id'));
        $this->assertSame($variant->name, $res->json('data.diff.name.before'));
        $this->assertSame([], $res->json('data.diff.attributes.before'));
        $this->assertSame($value->id, $res->json('data.diff.attributes.after.0.attribute_value_id'));
    }

    #[Test]
    public function invalid_bodies_answer_422_never_500(): void
    {
        $supplier = $this->supplier();
        $product = $this->product();
        $this->supply($supplier, $this->variant($product));
        Sanctum::actingAs($supplier);

        // خالی
        $this->postJson('/api/profile/supplier/products', [])->assertStatus(422)
            ->assertJsonValidationErrors(['name', 'category_id', 'slug', 'sku']);

        // آشغالِ تودرتو
        $this->postJson('/api/profile/supplier/products', [
            'name' => ['x'], 'variants' => 'not-an-array', 'order_units' => [['order_unit_id' => 'abc']], 'images' => 'nope',
        ])->assertStatus(422);

        // واریانت با SKU تکراری داخلِ همان درخواست
        $this->postJson('/api/profile/supplier/products', [
            'name' => 'x', 'category_id' => $product->category_id, 'slug' => 's-'.$this->uid(), 'sku' => 'k-'.$this->uid(),
            'variants' => [['name' => 'a', 'sku' => 'same'], ['name' => 'b', 'sku' => 'same']],
        ])->assertStatus(422)->assertJsonValidationErrors(['variants.0.sku']);

        // اسلاگِ گرفته‌شده
        $this->postJson('/api/profile/supplier/products', [
            'name' => 'x', 'category_id' => $product->category_id, 'slug' => $product->slug, 'sku' => 'k-'.$this->uid(),
        ])->assertStatus(422)->assertJsonValidationErrors(['slug']);

        // فیلدهای ادمین‌فقط نادیده گرفته می‌شوند، نه ۵۰۰
        $this->putJson("/api/profile/supplier/products/{$product->cuid}", ['is_featured' => true, 'description' => 'z'])
            ->assertCreated()
            ->assertJsonMissingPath('data.diff.is_featured');

        $this->putJson('/api/profile/supplier/variants/'.$product->variants()->first()->id, ['name' => ['x']])->assertStatus(422);
    }

    // ── تصمیمِ ادمین ──────────────────────────────────────────────────────

    #[Test]
    public function approving_a_create_request_builds_the_product_through_the_admin_write_path(): void
    {
        Storage::fake('public');
        Queue::fake();
        $supplier = $this->supplier();
        $category = $this->category();
        $unit = $this->unit();
        [$attribute, $value] = $this->attributePair();
        $body = $this->newProductBody($category, $unit, $attribute, $value);

        Sanctum::actingAs($supplier);
        $id = $this->post('/api/profile/supplier/products', $body + ['images' => [UploadedFile::fake()->image('a.jpg')]], ['Accept' => 'application/json'])
            ->assertCreated()->json('data.id');

        $bellBefore = UserNotification::where(UserNotification::USER_ID, $supplier->id)->count();

        Sanctum::actingAs($this->admin());
        $res = $this->postJson("/api/admin/product-change-requests/{$id}/approve", ['note' => 'خوب است'])->assertOk();

        $this->assertSame(ProductChangeRequest::STATUS_APPROVED, $res->json('data.status'));

        $product = Product::where(Product::SLUG, $body['slug'])->firstOrFail();
        $this->assertSame($body['name'], $product->name);
        $this->assertSame($category->id, $product->category_id);
        $this->assertSame($unit->id, $product->aggregate_limit_order_unit_id);
        $this->assertSame(12, $product->items_per_page);
        $this->assertSame($product->id, $res->json('data.product_id'));

        // واحدها
        $this->assertSame(50, (int) $product->orderUnits()->first()->pivot->max_quantity);

        // واریانت‌ها + ویژگی
        $this->assertSame(2, $product->variants()->count());
        $v12 = $product->variants()->where(ProductVariant::SKU, $body['variants'][0]['sku'])->firstOrFail();
        $this->assertSame($value->id, (int) $v12->variantAttributes()->first()->details->attribute_value_id);
        $v14 = $product->variants()->where(ProductVariant::SKU, $body['variants'][1]['sku'])->firstOrFail();
        $this->assertSame($unit->id, $v14->limit_order_unit_id);

        // رسانه: فایل به پوشه‌ی محصول منتقل و متصل شد
        $image = $product->images()->firstOrFail();
        $this->assertTrue($image->is_primary);
        $this->assertStringStartsWith('products/', $image->image_path);
        Storage::disk('public')->assertExists($image->image_path);

        // اعلان + پیامک به تأمین‌کننده
        Queue::assertPushed(SendSmsJob::class);
        $this->assertSame($bellBefore + 1, UserNotification::where(UserNotification::USER_ID, $supplier->id)->count());
        $row = UserNotification::where(UserNotification::USER_ID, $supplier->id)->latest(UserNotification::ID)->first();
        $this->assertSame(__('notifications.product_change_approved_subject'), $row->{UserNotification::SUBJECT});
        $this->assertStringContainsString($body['name'], $row->{UserNotification::BODY});

        // دوباره → ۴۰۹
        $this->postJson("/api/admin/product-change-requests/{$id}/approve")->assertStatus(409);

        // پیشنهاددهنده حالا «تأمین‌کننده‌ی» این کالاست — بی‌آنکه هنوز قیمتی گذاشته
        // باشد — پس می‌تواند ویرایشش را پیشنهاد دهد؛ تأمین‌کننده‌ی دیگر نه.
        Sanctum::actingAs($supplier);
        $this->getJson('/api/profile/supplier/products')->assertOk()->assertJsonPath('data.0.cuid', $product->cuid);
        $this->putJson("/api/profile/supplier/products/{$product->cuid}", ['description' => 'اصلاح تایپی'])->assertCreated();

        Sanctum::actingAs($this->supplier());
        $this->putJson("/api/profile/supplier/products/{$product->cuid}", ['description' => 'x'])->assertForbidden();
    }

    #[Test]
    public function approving_an_update_request_applies_only_the_diff(): void
    {
        Queue::fake();
        $supplier = $this->supplier();
        $product = $this->product();
        $variant = $this->variant($product);
        $this->supply($supplier, $variant);
        [$attribute, $value] = $this->attributePair();

        Sanctum::actingAs($supplier);
        $productReq = $this->putJson("/api/profile/supplier/products/{$product->cuid}", ['description' => 'توضیح تازه'])->assertCreated()->json('data.id');
        $variantReq = $this->putJson("/api/profile/supplier/variants/{$variant->id}", [
            'name' => 'نام تازه',
            'attributes' => [['attribute_id' => $attribute->id, 'attribute_value_id' => $value->id]],
        ])->assertCreated()->json('data.id');

        // بینِ ثبت و تأیید، ادمین نام را عوض می‌کند — پذیرش نباید آن را برگرداند.
        $product->update([Product::NAME => 'نام ادمین']);

        Sanctum::actingAs($this->admin());
        $this->postJson("/api/admin/product-change-requests/{$productReq}/approve")->assertOk();
        $this->postJson("/api/admin/product-change-requests/{$variantReq}/approve")->assertOk();

        $product->refresh();
        $this->assertSame('توضیح تازه', $product->description);
        $this->assertSame('نام ادمین', $product->name);

        $variant->refresh();
        $this->assertSame('نام تازه', $variant->name);
        $this->assertSame($value->id, (int) $variant->variantAttributes()->first()->details->attribute_value_id);
    }

    #[Test]
    public function a_slug_taken_since_the_proposal_fails_approval_with_422_not_500(): void
    {
        $supplier = $this->supplier();
        [$attribute, $value] = $this->attributePair();
        $body = $this->newProductBody($this->category(), $this->unit(), $attribute, $value);

        Sanctum::actingAs($supplier);
        $id = $this->postJson('/api/profile/supplier/products', $body)->assertCreated()->json('data.id');

        // کسی همان اسلاگ را گرفت
        $taken = $this->product();
        $taken->update([Product::SLUG => $body['slug']]);

        Sanctum::actingAs($this->admin());
        $this->postJson("/api/admin/product-change-requests/{$id}/approve")
            ->assertStatus(422)
            ->assertJsonValidationErrors(['slug']);

        $this->assertSame(ProductChangeRequest::STATUS_PENDING, ProductChangeRequest::find($id)->status);
    }

    #[Test]
    public function rejecting_requires_a_reason_and_tells_the_supplier(): void
    {
        Queue::fake();
        $supplier = $this->supplier();
        $product = $this->product();
        $this->supply($supplier, $this->variant($product));

        Sanctum::actingAs($supplier);
        $id = $this->putJson("/api/profile/supplier/products/{$product->cuid}", ['description' => 'x'])->assertCreated()->json('data.id');

        Sanctum::actingAs($this->admin());
        $this->postJson("/api/admin/product-change-requests/{$id}/reject", [])->assertStatus(422);

        $this->postJson("/api/admin/product-change-requests/{$id}/reject", ['note' => 'توضیح ناقص است'])
            ->assertOk()
            ->assertJsonPath('data.status', ProductChangeRequest::STATUS_REJECTED)
            ->assertJsonPath('data.admin_note', 'توضیح ناقص است');

        $this->assertSame('توضیح قبلی', $product->fresh()->description);

        Queue::assertPushed(SendSmsJob::class);
        $row = UserNotification::where(UserNotification::USER_ID, $supplier->id)->latest(UserNotification::ID)->firstOrFail();
        $this->assertStringContainsString('توضیح ناقص است', $row->{UserNotification::BODY});

        // تأمین‌کننده دلیل را در فهرستِ خودش می‌بیند
        Sanctum::actingAs($supplier);
        $this->getJson('/api/profile/supplier/change-requests')
            ->assertOk()
            ->assertJsonPath('data.0.admin_note', 'توضیح ناقص است')
            ->assertJsonPath('data.0.status', ProductChangeRequest::STATUS_REJECTED);
    }

    #[Test]
    public function the_admin_queue_defaults_to_pending_and_filters_by_type_and_status(): void
    {
        $supplier = $this->supplier();
        $product = $this->product();
        $this->supply($supplier, $this->variant($product));

        Sanctum::actingAs($supplier);
        $this->putJson("/api/profile/supplier/products/{$product->cuid}", ['description' => 'x'])->assertCreated();
        $rejectedId = $this->putJson("/api/profile/supplier/products/{$product->cuid}", ['description' => 'y'])->assertCreated()->json('data.id');

        Sanctum::actingAs($this->admin());
        $this->postJson("/api/admin/product-change-requests/{$rejectedId}/reject", ['note' => 'نه'])->assertOk();

        $this->getJson('/api/admin/product-change-requests')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/admin/product-change-requests?status=rejected')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.supplier.user_id', $supplier->id);
        $this->getJson('/api/admin/product-change-requests?type=create_variant')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("/api/admin/product-change-requests/{$rejectedId}")->assertOk()->assertJsonPath('data.diff.description.after', 'y');
    }

    // ── انصراف ────────────────────────────────────────────────────────────

    #[Test]
    public function a_supplier_can_withdraw_only_their_own_pending_request(): void
    {
        $supplier = $this->supplier();
        $product = $this->product();
        $this->supply($supplier, $this->variant($product));

        Sanctum::actingAs($supplier);
        $id = $this->putJson("/api/profile/supplier/products/{$product->cuid}", ['description' => 'x'])->assertCreated()->json('data.id');

        Sanctum::actingAs($this->supplier());
        $this->deleteJson("/api/profile/supplier/change-requests/{$id}")->assertForbidden();

        Sanctum::actingAs($supplier);
        $this->deleteJson("/api/profile/supplier/change-requests/{$id}")
            ->assertOk()
            ->assertJsonPath('data.status', ProductChangeRequest::STATUS_WITHDRAWN);

        $this->deleteJson("/api/profile/supplier/change-requests/{$id}")->assertStatus(422);

        Sanctum::actingAs($this->admin());
        $this->postJson("/api/admin/product-change-requests/{$id}/approve")->assertStatus(409);
    }
}
