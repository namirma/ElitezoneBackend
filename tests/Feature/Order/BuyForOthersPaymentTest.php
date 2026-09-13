<?php

namespace Tests\Feature\Order;

use App\Jobs\Notification\SendSmsJob;
use App\Models\Address;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\FactoryStock;
use App\Models\Order;
use App\Models\OrderUnit;
use App\Models\Setting;
use App\Models\ShippingMethod;
use App\Models\SupplierProfile;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Invoice\InvoiceGenerator;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laratrust\Models\Role;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Pricing\CreatesPricingFixtures;
use Tests\TestCase;

/**
 * «الیت‌یار سبد را می‌بندد، کارفرما پولش را می‌دهد» — فاز ۶.
 *
 * نقصی که این فاز می‌بندد واقعی و تأییدشده بود: `WalletTransactionEngine` کیف
 * پولِ خریدار (WO) را همیشه به `placer_user_id` می‌بست، و در این مسیر
 * ثبت‌کننده خودِ الیت‌یار است — پس پولِ کالای کارفرما از کیف پولِ الیت‌یار کسر
 * می‌شد، در حالی که همان شخص روی همان سفارش کمیسیون هم می‌گرفت.
 *
 * تصمیم‌های مدیریت که اینجا پین می‌شوند: لنگرِ لینک **سبد** است (پرسش ۱/ب)،
 * پرداخت **همیشه** از کیف پول کارفرماست (پرسش ۳/الف)، و اعتبارِ لینک همان
 * مهلتِ سبد است (پرسش ۴) که با انقضایش موجودی آزاد می‌شود (پرسش ۵).
 */
class BuyForOthersPaymentTest extends TestCase
{
    use CreatesPricingFixtures;
    use DatabaseTransactions;

    private User $eliteYar;

    private User $employer;

    private $factory;

    private $location;

    private $variant;

    private ShippingMethod $shipping;

    private int $supplierId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mock(InvoiceGenerator::class, function ($mock) {
            $mock->shouldReceive('generateDirectPurchaseDocs')->andReturnNull();
            $mock->shouldReceive('generateReservationDocs')->andReturnNull();
        });

        Setting::updateOrCreate([Setting::KEY => 'shaparak_value'], [Setting::VALUE => '2000000000']);
        Setting::updateOrCreate([Setting::KEY => 'reserve_value'], [Setting::VALUE => '2000000000']);
        Cache::forget(Setting::CACHE_KEY);

        // الیت‌یار باید نقشِ engineer/contractor داشته باشد وگرنه
        // `PlaceOrderAction` او را الیت‌یارِ مؤثر نمی‌شناسد و کمیسیون مسیر
        // نمی‌گیرد (`User::isEliteYAR`).
        $this->eliteYar = User::factory()->create();
        $this->eliteYar->addRole(Role::firstOrCreate(['name' => 'engineer'], ['display_name' => 'Engineer']));

        $this->employer = User::factory()->create();
        $this->employer->addRole(Role::firstOrCreate(['name' => 'employer'], ['display_name' => 'Employer']));

        // رابطه‌ی تأییدشده‌ی الیت‌یار↔کارفرما — بدون آن صدور لینک باید رد شود.
        $this->eliteYar->associatedEmployers()->attach($this->employer->id, ['status' => 'approved']);

        $supplier = User::factory()->create();
        Wallet::create([Wallet::USER_ID => $supplier->id, Wallet::BALANCE => 0, Wallet::BALANCE_HOLD => 0]);
        SupplierProfile::create([SupplierProfile::USER_ID => $supplier->id])
            ->forceFill([
                SupplierProfile::STATUS => SupplierProfile::STATUS_APPROVED,
                SupplierProfile::RESERVE_TIME => now()->addDays(3),
            ])->save();
        $this->supplierId = $supplier->id;

        $this->factory = $this->makeFactory();
        $this->factory->forceFill(['supplier_user_id' => $supplier->id])->save();
        $this->location = $this->makeLocation($this->factory);
        $this->variant = $this->makeVariant();
        $this->makePrice($this->variant, $this->factory, $this->location, general: 1_000_000, special: 900_000);
        $this->setStock(5000);

        $this->shipping = ShippingMethod::create([
            ShippingMethod::NAME => 'تست حمل',
            ShippingMethod::CODE => 'SHIP_'.Str::random(6),
        ]);

        $sys = User::factory()->create();
        config(['financial.system_user_id' => $sys->id]);
        Wallet::create([Wallet::USER_ID => $sys->id, Wallet::BALANCE => 0, Wallet::BALANCE_HOLD => 0]);
    }

    private function setStock(float $quantity): void
    {
        FactoryStock::updateOrCreate([
            'product_variant_id' => $this->variant->id,
            'factory_id' => $this->factory->id,
            'factory_location_id' => $this->location->id,
        ], ['quantity' => $quantity]);
    }

    private function fillCart(): Cart
    {
        $unit = OrderUnit::create([
            OrderUnit::NAME => 'کیلوگرم',
            OrderUnit::CODE => 'KG_'.Str::random(6),
            OrderUnit::UNIT => 'kg',
            OrderUnit::AMOUNT => 1,
        ]);

        $cart = Cart::create([Cart::USER_ID => $this->eliteYar->id]);
        $cart->forceFill([
            Cart::SUPPLIER_USER_ID => $this->supplierId,
            Cart::PARKED_UNTIL => now()->addHours(2),
            Cart::SHIPPING_METHOD_ID => $this->shipping->id,
        ])->save();

        $kg = 100;

        $cart->items()->create([
            CartItem::PRODUCT_VARIANT_ID => $this->variant->id,
            CartItem::FACTORY_ID => $this->factory->id,
            CartItem::FACTORY_LOCATION_ID => $this->location->id,
            CartItem::SHIPPING_METHOD_ID => $this->shipping->id,
            CartItem::QUANTITIES => [(string) $unit->id => $kg],
            CartItem::WEIGHT => $kg,
            CartItem::QUANTITY => $kg,
            CartItem::UNIT_NAME => 'کیلوگرم',
            CartItem::PRICE => 900_000,
            CartItem::FINAL_PRICE => 900_000,
            CartItem::VAT_AMOUNT => 90_000,
            CartItem::TOTAL_WITH_VAT => $kg * 990_000,
        ]);

        return $cart->fresh(['items']);
    }

    private function fundEmployer(float $amount): void
    {
        Wallet::updateOrCreate(
            [Wallet::USER_ID => $this->employer->id],
            [Wallet::BALANCE => $amount, Wallet::BALANCE_HOLD => 0]
        );
    }

    private function addressFor(User $u): Address
    {
        return Address::create([
            Address::USER_ID => $u->id,
            Address::TITLE => 'خانه',
            Address::POSTAL_ADDRESS => 'آدرس تست',
            Address::RECEIVER_NAME => 'گیرنده',
            Address::RECEIVER_MOBILE => '09120000000',
        ]);
    }

    private function bind(Cart $cart): Cart
    {
        $cart->forceFill([Cart::BENEFICIARY_USER_ID => $this->employer->id])->save();

        return $cart->fresh();
    }

    // ── صدور لینک ────────────────────────────────────────────────────────────

    #[Test]
    public function issuing_a_link_binds_the_cart_to_the_employer_and_sends_an_sms(): void
    {
        Queue::fake();
        $this->fillCart();
        Sanctum::actingAs($this->eliteYar);

        $this->postJson('/api/cart/payment-link', [
            'beneficiary_user_id' => $this->employer->id,
        ])->assertOk();

        $cart = Cart::queryForUser($this->eliteYar->id)->first();

        $this->assertSame($this->employer->id, (int) $cart->{Cart::BENEFICIARY_USER_ID});
        $this->assertNotNull($cart->{Cart::PAYMENT_LINK_SENT_AT});
        Queue::assertPushed(SendSmsJob::class);
    }

    #[Test]
    public function a_link_cannot_be_issued_for_an_employer_who_is_not_approved(): void
    {
        Queue::fake();
        $this->fillCart();

        $stranger = User::factory()->create();
        Sanctum::actingAs($this->eliteYar);

        $this->postJson('/api/cart/payment-link', [
            'beneficiary_user_id' => $stranger->id,
        ])->assertStatus(422);

        Queue::assertNothingPushed();
    }

    // ── گاردِ «الیت‌یار خودش نمی‌پردازد» (پرسش ۳) ────────────────────────────

    #[Test]
    public function the_elite_yar_cannot_pay_a_cart_bound_to_an_employer(): void
    {
        $this->bind($this->fillCart());
        Wallet::updateOrCreate([Wallet::USER_ID => $this->eliteYar->id],
            [Wallet::BALANCE => 900_000_000_000, Wallet::BALANCE_HOLD => 0]);

        Sanctum::actingAs($this->eliteYar);

        // موجودی الیت‌یار عمداً فراوان است: اگر گارد نباشد، پرداخت موفق می‌شود.
        // درخواست از هر جهتِ دیگری معتبر است تا ۴۲۲ نتواند از اعتبارسنجی بیاید،
        // و پیام هم صریح بررسی می‌شود چون هر دو حالت ۴۲۲ برمی‌گردانند.
        $response = $this->postJson('/api/checkout/pay', [
            'address_id' => $this->addressFor($this->eliteYar)->id,
            'shipping_method_id' => $this->shipping->id,
            'beneficiary_user_id' => $this->employer->id,
        ])->assertStatus(422);

        $this->assertSame(
            __('checkout.elite_yar_cannot_pay_for_beneficiary'),
            $response->json('message')
        );
        $this->assertSame(0, Order::query()->count());
    }

    // ── دسترسی به لینک ───────────────────────────────────────────────────────

    #[Test]
    public function a_stranger_cannot_open_someone_elses_payment_link(): void
    {
        $cart = $this->bind($this->fillCart());

        Sanctum::actingAs(User::factory()->create());
        $this->getJson("/api/checkout/pay-for/{$cart->id}")->assertStatus(403);
    }

    #[Test]
    public function a_cart_with_no_link_issued_is_not_payable_through_the_link_route(): void
    {
        $cart = $this->fillCart();

        Sanctum::actingAs($this->employer);
        $this->getJson("/api/checkout/pay-for/{$cart->id}")->assertStatus(404);
    }

    #[Test]
    public function an_expired_cart_deadline_refuses_the_payment(): void
    {
        $cart = $this->bind($this->fillCart());
        $cart->forceFill([Cart::PARKED_UNTIL => now()->subMinute()])->save();

        $this->fundEmployer(900_000_000_000);
        Sanctum::actingAs($this->employer);

        $this->getJson("/api/checkout/pay-for/{$cart->id}")->assertStatus(422);
        $this->assertSame(0, Order::query()->count());
    }

    // ── هسته‌ی فاز ۶ ─────────────────────────────────────────────────────────

    #[Test]
    public function paying_the_link_debits_the_employer_and_never_the_elite_yar(): void
    {
        $cart = $this->bind($this->fillCart());

        $this->fundEmployer(900_000_000_000);
        $eliteYarWallet = Wallet::create([
            Wallet::USER_ID => $this->eliteYar->id,
            Wallet::BALANCE => 0,
            Wallet::BALANCE_HOLD => 0,
        ]);

        $employerBefore = (float) Wallet::where(Wallet::USER_ID, $this->employer->id)->value(Wallet::BALANCE);

        Sanctum::actingAs($this->employer);
        $this->postJson("/api/checkout/pay-for/{$cart->id}")->assertOk();

        $employerAfter = (float) Wallet::where(Wallet::USER_ID, $this->employer->id)->value(Wallet::BALANCE);

        $this->assertLessThan($employerBefore, $employerAfter,
            'پول باید از کیف پول کارفرما کسر شده باشد.');

        // الیت‌یار صفر شروع کرد؛ اگر WO هنوز به ثبت‌کننده بسته بود، اینجا منفی
        // می‌شد یا استثنای «موجودی کافی نیست» می‌گرفتیم.
        $this->assertSame(0.0, (float) $eliteYarWallet->fresh()->{Wallet::BALANCE},
            'کیف پول الیت‌یار نباید بابت کالای کارفرما دست بخورد.');
    }

    #[Test]
    public function the_order_records_the_employer_as_payer_and_the_elite_yar_as_commission_earner(): void
    {
        $cart = $this->bind($this->fillCart());
        $this->fundEmployer(900_000_000_000);

        Sanctum::actingAs($this->employer);
        $this->postJson("/api/checkout/pay-for/{$cart->id}")->assertOk();

        $order = Order::query()->latest('id')->firstOrFail();

        $this->assertSame($this->eliteYar->id, (int) $order->{Order::PLACER_USER_ID},
            'ثبت‌کننده همان الیت‌یاری است که سبد را بست.');
        $this->assertSame($this->employer->id, (int) $order->{Order::PAYER_USER_ID},
            'پرداخت‌کننده کارفرماست.');
        $this->assertSame($this->employer->id, (int) $order->{Order::BENEFICIARY_USER_ID},
            'ذی‌نفع کارفرماست.');
        $this->assertSame($this->eliteYar->id, (int) $order->{Order::ELITE_YAR_USER_ID},
            'کمیسیون به الیت‌یار می‌رسد.');

        // و همان چیزی که بقیه‌ی سیستم می‌خواند:
        $this->assertSame($this->employer->id, $order->payerUserId());
    }

    #[Test]
    public function the_cart_is_cleared_after_the_employer_pays(): void
    {
        $cart = $this->bind($this->fillCart());
        $this->fundEmployer(900_000_000_000);

        Sanctum::actingAs($this->employer);
        $this->postJson("/api/checkout/pay-for/{$cart->id}")->assertOk();

        $this->assertSame(0, CartItem::where(CartItem::CART_ID, $cart->id)->count());
    }

    // ── سازگاری با گذشته ─────────────────────────────────────────────────────

    #[Test]
    public function payer_falls_back_to_the_placer_when_the_column_is_empty(): void
    {
        // ردیف‌های پیش از فاز ۶ ستون را ندارند؛ `payerUserId()` نباید null بدهد،
        // وگرنه هر مسیرِ مالیِ سفارش‌های قدیمی می‌شکند.
        $order = Order::create([
            Order::PLACER_USER_ID => $this->eliteYar->id,
            Order::ORDER_NUMBER => 'LEGACY-'.Str::random(8),
            Order::STATUS => Order::STATUS_PROCESSING,
            Order::PAYMENT_STATUS => Order::PAYMENT_PENDING,
            Order::FINAL_AMOUNT => 1_000_000,
        ]);

        $order->forceFill([Order::PAYER_USER_ID => null])->save();

        $this->assertSame($this->eliteYar->id, $order->fresh()->payerUserId());
    }
}
