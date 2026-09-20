<?php

declare(strict_types=1);

namespace Tests\Feature\Supplier;

use App\Jobs\Notification\SendSmsJob;
use App\Models\Address;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\FactoryStock;
use App\Models\OrderUnit;
use App\Models\ProductPrice;
use App\Models\ProductVariant;
use App\Models\Setting;
use App\Models\ShippingMethod;
use App\Models\SmsTemplate;
use App\Models\SupplierProfile;
use App\Models\SupplierRequest;
use App\Models\User;
use App\Models\UserNotification;
use App\Models\Wallet;
use App\Services\Invoice\InvoiceGenerator;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laratrust\Models\Role;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\GrantsAdminPermissions;
use Tests\Feature\Pricing\CreatesPricingFixtures;
use Tests\TestCase;

/**
 * «تا تأیید نشده کالا موقتاً از کاتالوگ خارج می‌شود» + «مهلت ۴۸ ساعت» — دو بندِ
 * باقی‌مانده‌ی تصمیم مدیریت ۲۰۲۶-۰۹-۰۹ (پرسش ۵/۷).
 *
 * مبنای «آخرین قیمتِ تأییدشده» را `SupplierProductPriceManagementTest` پین
 * کرده؛ این‌جا رفتارِ مشتری‌محور است: چه کسی چه چیزی را نمی‌بیند، و کِی برمی‌گردد.
 */
class PendingPriceLeavesCatalogTest extends TestCase
{
    use CreatesPricingFixtures;
    use DatabaseTransactions;
    use GrantsAdminPermissions;

    private User $supplier;

    private $factory;

    private $location;

    private $product;

    private ProductVariant $variant;

    private ProductVariant $sibling;

    private ProductPrice $price;

    protected function setUp(): void
    {
        parent::setUp();

        Setting::query()->updateOrCreate(
            [Setting::KEY => 'price_change_approval_percent'],
            [Setting::VALUE => '15'],
        );

        $this->supplier = User::factory()->create();
        $this->supplier->addRole(Role::firstOrCreate(['name' => 'supplier'], ['display_name' => 'Supplier']));
        Wallet::create([Wallet::USER_ID => $this->supplier->id, Wallet::BALANCE => 0, Wallet::BALANCE_HOLD => 0]);
        // بدونِ پنجره‌ی اعتبارِ زنده، `hideProductsWithExpiredPrices` خودش محصول را
        // پنهان می‌کرد و این تست چیزی را ثابت نمی‌کرد.
        SupplierProfile::create([SupplierProfile::USER_ID => $this->supplier->id])
            ->forceFill([
                SupplierProfile::STATUS => SupplierProfile::STATUS_APPROVED,
                SupplierProfile::RESERVE_TIME => now()->addDays(3),
            ])->save();

        $this->factory = $this->makeFactory();
        $this->factory->forceFill(['supplier_user_id' => $this->supplier->id])->save();
        $this->location = $this->makeLocation($this->factory);

        $this->product = $this->makeProduct();
        $this->variant = $this->makeVariant($this->product, ['name' => 'سایز ۱۲']);
        $this->sibling = $this->makeVariant($this->product, ['name' => 'سایز ۱۴']);
        $this->price = $this->makePrice($this->variant, $this->factory, $this->location, general: 1_000_000, special: 900_000);
        $this->makePrice($this->sibling, $this->factory, $this->location, general: 1_000_000, special: 900_000);
    }

    /** از مسیرِ واقعیِ تأمین‌کننده به صف می‌رود — نه با ساختنِ دستیِ ردیف. */
    private function queueChange(): SupplierRequest
    {
        Sanctum::actingAs($this->supplier);
        $this->putJson("/api/profile/supplier/prices/{$this->price->id}", [
            ProductPrice::GENERAL_PRICE => 1_400_000,   // ۴۰٪ — بالای آستانه
        ])->assertOk()->assertJsonPath('data.queued_for_approval', true);

        return SupplierRequest::query()->pendingPriceChange()->latest('id')->firstOrFail();
    }

    private function listedVariantIds(): array
    {
        return collect($this->getJson("/api/products/{$this->product->cuid}/variants")->assertOk()->json('data.data'))
            ->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    private function actAsAdmin(): void
    {
        $admin = User::factory()->create();
        $admin->addRole(Role::firstOrCreate(['name' => 'admin'], ['display_name' => 'Admin']));
        $this->grantAllAdminPermissions($admin);
        Sanctum::actingAs($admin);
    }

    // ── خروج از کاتالوگ ─────────────────────────────────────────────────────

    #[Test]
    public function a_pending_price_change_hides_only_that_variant_from_the_public_list(): void
    {
        $this->assertContains($this->variant->id, $this->listedVariantIds(), 'پیش از درخواست باید دیده شود.');

        $this->queueChange();
        $this->app['auth']->forgetGuards();

        $ids = $this->listedVariantIds();
        $this->assertNotContains($this->variant->id, $ids, 'واریانتِ در انتظار تأیید نباید در فهرست باشد.');
        $this->assertContains($this->sibling->id, $ids, 'واریانتِ همسایه که درخواستی ندارد باید بماند.');
    }

    #[Test]
    public function the_product_itself_is_still_listed_while_one_variant_is_hidden(): void
    {
        $this->queueChange();
        $this->app['auth']->forgetGuards();

        $cuids = collect($this->getJson('/api/products?per_page=100')->assertOk()->json('data.data'))->pluck('cuid');
        $this->assertContains($this->product->cuid, $cuids, 'محصول ۴۰۴ یا پنهان نمی‌شود؛ فقط واریانت.');
    }

    #[Test]
    public function a_deep_link_to_the_hidden_variant_renders_without_prices_and_says_so(): void
    {
        $this->queueChange();
        $this->app['auth']->forgetGuards();

        $this->getJson("/api/products/variants/{$this->variant->id}")
            ->assertOk()
            ->assertJsonPath('data.variant.price_under_review', true)
            ->assertJsonPath('data.variant.prices', []);

        $this->getJson("/api/products/variants/{$this->sibling->id}")
            ->assertOk()
            ->assertJsonPath('data.variant.price_under_review', false)
            ->assertJsonCount(1, 'data.variant.prices');
    }

    #[Test]
    public function an_expired_suppliers_price_row_is_not_served_next_to_a_live_one(): void
    {
        // همان واریانت، یک تأمین‌کننده‌ی دوم با پنجره‌ی منقضی. محصول در فهرست
        // می‌ماند (یک قیمتِ زنده دارد) ولی ردیفِ منقضی نباید به سایت برسد — سایت
        // `prices[0]` را به سبد می‌فرستد و ردیفِ منقضی آن‌جا ۴۲۲ می‌شود.
        $expired = User::factory()->create();
        $expired->addRole(Role::firstOrCreate(['name' => 'supplier'], ['display_name' => 'Supplier']));
        SupplierProfile::create([SupplierProfile::USER_ID => $expired->id])
            ->forceFill([
                SupplierProfile::STATUS => SupplierProfile::STATUS_APPROVED,
                SupplierProfile::RESERVE_TIME => now()->subHour(),
            ])->save();
        $expiredFactory = $this->makeFactory();
        $expiredFactory->forceFill(['supplier_user_id' => $expired->id])->save();
        $this->makePrice($this->variant, $expiredFactory, $this->makeLocation($expiredFactory), general: 500_000, special: 400_000);

        $deepLink = $this->getJson("/api/products/variants/{$this->variant->id}")->assertOk();
        $deepLink->assertJsonCount(1, 'data.variant.prices')
            ->assertJsonPath('data.variant.prices.0.factory.id', (string) $this->factory->id);

        $listed = collect($this->getJson("/api/products/{$this->product->cuid}/variants")->assertOk()->json('data.data'))
            ->firstWhere('id', $this->variant->id);
        $this->assertCount(1, $listed['prices']);

        $product = collect($this->getJson('/api/products?per_page=100')->assertOk()->json('data.data'))
            ->firstWhere('cuid', $this->product->cuid);
        $this->assertNotNull($product, 'محصول با یک قیمتِ زنده باید در فهرست بماند');
    }

    #[Test]
    public function the_hidden_variant_cannot_be_added_to_a_cart(): void
    {
        $this->queueChange();

        $unit = OrderUnit::create([
            OrderUnit::NAME => 'کیلوگرم', OrderUnit::CODE => 'KG_'.Str::random(6), OrderUnit::UNIT => 'kg', OrderUnit::AMOUNT => 1,
        ]);
        $shipping = ShippingMethod::create([ShippingMethod::NAME => 'حمل', ShippingMethod::CODE => 'SHIP_'.Str::random(6)]);
        FactoryStock::updateOrCreate([
            'product_variant_id' => $this->variant->id,
            'factory_id' => $this->factory->id,
            'factory_location_id' => $this->location->id,
        ], ['quantity' => 5000]);

        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/cart/items', [
            'product_variant_id' => $this->variant->id,
            'factory_id' => $this->factory->id,
            'location_id' => $this->location->id,
            'shipping_method_id' => $shipping->id,
            'quantities' => [(string) $unit->id => 10],
        ])->assertStatus(422)->assertJsonPath('message', __('supplier.price_under_review'));
    }

    // ── بازگشت به کاتالوگ ────────────────────────────────────────────────────

    #[Test]
    public function approval_brings_the_variant_back_at_the_new_price(): void
    {
        $request = $this->queueChange();

        $this->actAsAdmin();
        $this->postJson("/api/admin/supplier-requests/{$request->id}/approve")->assertOk();
        $this->app['auth']->forgetGuards();

        $this->assertContains($this->variant->id, $this->listedVariantIds());
        $this->assertSame(1_400_000.0, (float) $this->price->fresh()->{ProductPrice::GENERAL_PRICE});
    }

    #[Test]
    public function rejection_brings_the_variant_back_at_the_old_price(): void
    {
        $request = $this->queueChange();

        $this->actAsAdmin();
        $this->postJson("/api/admin/supplier-requests/{$request->id}/reject", ['note' => 'نه'])->assertOk();
        $this->app['auth']->forgetGuards();

        $this->assertContains($this->variant->id, $this->listedVariantIds());
        $this->assertSame(1_000_000.0, (float) $this->price->fresh()->{ProductPrice::GENERAL_PRICE});
    }

    // ── مهلت ۴۸ ساعته ────────────────────────────────────────────────────────

    #[Test]
    public function expiry_rejects_with_the_deadline_reason_notifies_the_supplier_and_restores_the_variant(): void
    {
        Queue::fake();
        $request = $this->queueChange();
        $request->forceFill([SupplierRequest::REVIEW_DEADLINE => now()->subMinute()])->save();
        $this->app['auth']->forgetGuards();

        $before = UserNotification::where(UserNotification::USER_ID, $this->supplier->id)->count();

        $this->artisan('supplier-requests:expire-price-changes')->assertSuccessful();

        $request->refresh();
        $this->assertSame(SupplierRequest::STATUS_REJECTED, $request->{SupplierRequest::STATUS});
        $this->assertTrue($request->isExpiredByDeadline());
        $this->assertStringContainsString('مهلتِ 48 ساعته گذشت', $request->{SupplierRequest::REVIEW_NOTE});

        // اعلان از همان مسیرِ موجود: پیامک + ردیفِ زنگوله (`Notifier`).
        // ویژگی‌های job محافظت‌شده‌اند؛ closure با bind به خودِ job می‌خواندشان.
        $mobile = $this->supplier->mobile;
        Queue::assertPushed(
            SendSmsJob::class,
            fn (SendSmsJob $job) => (fn () => $this->mobile === $mobile
                && $this->templateKey !== null
                && str_contains((string) SmsTemplate::render($this->templateKey, $this->params, $this->mobile), 'در مهلت 48 ساعته'))
                ->call($job)
        );
        $this->assertSame(
            $before + 1,
            UserNotification::where(UserNotification::USER_ID, $this->supplier->id)->count(),
        );

        // برگشت به کاتالوگ با همان قیمتِ قبلی — پیشنهاد هرگز روی ردیف ننشسته بود.
        $this->assertContains($this->variant->id, $this->listedVariantIds());
        $this->assertSame(1_000_000.0, (float) $this->price->fresh()->{ProductPrice::GENERAL_PRICE});
    }

    #[Test]
    public function the_supplier_price_list_shows_the_pending_change_and_its_deadline(): void
    {
        $request = $this->queueChange();

        $row = collect($this->getJson('/api/profile/supplier/prices')->assertOk()->json('data'))
            ->firstWhere('id', $this->price->id);

        $this->assertSame($request->id, $row['pending_change']['id']);
        $this->assertSame('1400000', (string) $row['pending_change']['proposed_general_price']);
        $this->assertNotNull($row['pending_change']['review_deadline']);

        $other = collect($this->getJson('/api/profile/supplier/prices')->json('data'))
            ->firstWhere('product_variant_id', $this->sibling->id);
        $this->assertNull($other['pending_change']);
    }

    /**
     * QA 2026-09-15 یافته‌ی ۸ («صف ردیفِ pending را نشان نداد») روی dev بازتولید
     * نشد، ولی هیچ تستی هم شاخه‌ی پیش‌فرضِ صف (بدونِ فیلتر ⇒ pending) را نمی‌سنجید.
     */
    #[Test]
    public function the_admin_queue_lists_a_fresh_pending_price_change_by_default(): void
    {
        $request = $this->queueChange();

        $this->actAsAdmin();
        $response = $this->getJson('/api/admin/supplier-requests')->assertOk();

        $row = collect($response->json('data'))->firstWhere('id', $request->id);

        $this->assertNotNull($row, 'ردیفِ pendingِ تازه باید بدونِ هیچ فیلتری در صف باشد.');
        $this->assertSame('pending', $row['status']);
        $this->assertSame('price_change', $row['type']);
        $this->assertGreaterThanOrEqual(1, $response->json('meta.total'));
    }

    /** قیمتِ پایه‌ی صفر: درخواست باید واقعاً به صف برسد، نه اینکه روی `INF` ۵۰۰ بدهد. */
    #[Test]
    public function pricing_a_row_from_zero_reaches_the_queue(): void
    {
        // مبنای تأییدشده هم صفر — ردیفی که هرگز قیمت‌گذاری نشده.
        $this->price->forceFill([
            ProductPrice::GENERAL_PRICE => 0,
            ProductPrice::SPECIAL_PRICE => 0,
            ProductPrice::APPROVED_GENERAL_PRICE => 0,
            ProductPrice::APPROVED_SPECIAL_PRICE => 0,
        ])->save();

        Sanctum::actingAs($this->supplier);
        $this->putJson("/api/profile/supplier/prices/{$this->price->id}", [
            ProductPrice::GENERAL_PRICE => 1_400_000,
        ])->assertOk()->assertJsonPath('data.queued_for_approval', true);

        $request = SupplierRequest::query()->pendingPriceChange()->latest('id')->firstOrFail();

        $this->assertSame($this->price->id, (int) $request->{SupplierRequest::PRODUCT_PRICE_ID});
        $this->assertNull($request->{SupplierRequest::PAYLOAD}['change_percent']);
    }

    #[Test]
    public function the_admin_queue_marks_an_expired_request_apart_from_an_admin_rejection(): void
    {
        $request = $this->queueChange();
        $request->forceFill([SupplierRequest::REVIEW_DEADLINE => now()->subMinute()])->save();
        $this->artisan('supplier-requests:expire-price-changes')->assertSuccessful();

        $this->actAsAdmin();
        // پاسخِ paginator در این پروژه `data` (آرایه) + `meta` است — نه `data.data`.
        $row = collect($this->getJson('/api/admin/supplier-requests?status=rejected&per_page=100')->assertOk()->json('data'))
            ->firstWhere('id', $request->id);

        $this->assertTrue($row['expired']);
    }

    // ── مسیرِ پول ────────────────────────────────────────────────────────────

    /**
     * آیتمی که **پیش از** ثبتِ درخواست در سبد نشسته بود از گاردِ افزودن رد شده؛
     * ثبتِ سفارش باید خودش جلویش را بگیرد، وگرنه همان قیمتی فروخته می‌شود که
     * تأمین‌کننده گفته اشتباه است.
     */
    #[Test]
    public function checkout_refuses_a_cart_that_already_holds_the_variant(): void
    {
        $this->mock(InvoiceGenerator::class, function ($mock) {
            $mock->shouldReceive('generateDirectPurchaseDocs')->andReturnNull();
        });
        Setting::updateOrCreate([Setting::KEY => 'shaparak_value'], [Setting::VALUE => '2000000000']);
        Setting::updateOrCreate([Setting::KEY => 'reserve_value'], [Setting::VALUE => '2000000000']);
        Cache::forget(Setting::CACHE_KEY);

        $buyer = User::factory()->create();
        $buyer->addRole(Role::firstOrCreate(['name' => 'employer'], ['display_name' => 'Employer']));
        Wallet::create([Wallet::USER_ID => $buyer->id, Wallet::BALANCE => 900_000_000_000, Wallet::BALANCE_HOLD => 0]);
        $sys = User::factory()->create();
        config(['financial.system_user_id' => $sys->id]);
        Wallet::create([Wallet::USER_ID => $sys->id, Wallet::BALANCE => 0, Wallet::BALANCE_HOLD => 0]);

        $unit = OrderUnit::create([
            OrderUnit::NAME => 'کیلوگرم', OrderUnit::CODE => 'KG_'.Str::random(6), OrderUnit::UNIT => 'kg', OrderUnit::AMOUNT => 1,
        ]);
        $shipping = ShippingMethod::create([ShippingMethod::NAME => 'حمل', ShippingMethod::CODE => 'SHIP_'.Str::random(6)]);
        FactoryStock::updateOrCreate([
            'product_variant_id' => $this->variant->id,
            'factory_id' => $this->factory->id,
            'factory_location_id' => $this->location->id,
        ], ['quantity' => 5000]);

        $cart = Cart::create([Cart::USER_ID => $buyer->id]);
        $cart->forceFill([
            Cart::SUPPLIER_USER_ID => $this->supplier->id,
            Cart::PARKED_UNTIL => now()->addHours(2),
            Cart::SHIPPING_METHOD_ID => $shipping->id,
        ])->save();
        $cart->items()->create([
            CartItem::PRODUCT_VARIANT_ID => $this->variant->id,
            CartItem::FACTORY_ID => $this->factory->id,
            CartItem::FACTORY_LOCATION_ID => $this->location->id,
            CartItem::SHIPPING_METHOD_ID => $shipping->id,
            CartItem::QUANTITIES => [(string) $unit->id => 100],
            CartItem::WEIGHT => 100,
            CartItem::QUANTITY => 100,
            CartItem::UNIT_NAME => 'کیلوگرم',
            CartItem::PRICE => 900_000,
            CartItem::FINAL_PRICE => 900_000,
            CartItem::VAT_AMOUNT => 90_000,
            CartItem::TOTAL_WITH_VAT => 99_000_000,
        ]);

        $this->queueChange();   // بعد از این‌که آیتم در سبد نشست

        Sanctum::actingAs($buyer);
        $address = Address::create([
            Address::USER_ID => $buyer->id,
            Address::TITLE => 'خانه',
            Address::POSTAL_ADDRESS => 'آدرس تست',
            Address::RECEIVER_NAME => 'گیرنده',
            Address::RECEIVER_MOBILE => '09120000000',
        ]);

        $this->postJson('/api/checkout/pay', [
            'address_id' => $address->id,
            'shipping_method_id' => $shipping->id,
        ])->assertStatus(422)->assertJsonPath('message', __('supplier.price_under_review'));

        $this->assertDatabaseMissing('orders', ['placer_user_id' => $buyer->id]);
    }
}
