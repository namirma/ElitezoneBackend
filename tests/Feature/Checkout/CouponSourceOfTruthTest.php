<?php

declare(strict_types=1);

namespace Tests\Feature\Checkout;

use App\Models\Address;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Coupon;
use App\Models\CouponFunder;
use App\Models\FactoryStock;
use App\Models\Order;
use App\Models\OrderUnit;
use App\Models\Role;
use App\Models\Setting;
use App\Models\ShippingMethod;
use App\Models\SupplierProfile;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Cart\CartCheckoutTotals;
use App\Services\Invoice\InvoiceGenerator;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Pricing\CreatesPricingFixtures;
use Tests\TestCase;

/**
 * کوپن یک منبعِ حقیقت دارد: **ستونِ `carts.coupon_code`** — ممیزیِ ۲۰۲۶-۰۹-۱۲، باگ ۲.
 *
 * تصمیمِ شاخه (`payment-options`, `pay`) همیشه از همان ستون می‌خواند، ولی
 * `PlaceOrderAction` تا امروز فقط `coupon_code`ِ بدنه‌ی درخواست را می‌دید. فرانت
 * آن را فقط پس از موفقیتِ یک پیش‌نمایشِ ناهم‌زمان می‌فرستاد؛ کاربری که بعد از
 * رفرش زودتر می‌زد، سفارشِ **بدونِ تخفیف** می‌گرفت در حالی که صفحه و شاخه با
 * تخفیف حساب شده بودند — یعنی برداشتِ بیشتر از عددِ نمایش‌داده‌شده.
 *
 * fixtures عیناً از `ReserveBranchGuardTest` — همان سبدِ تک‌ردیفیِ ۹۹۰٬۰۰۰ ریال/کیلو.
 */
class CouponSourceOfTruthTest extends TestCase
{
    use CreatesPricingFixtures;
    use DatabaseTransactions;

    private User $buyer;

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

        $this->buyer = User::factory()->create();

        $supplier = User::factory()->create();
        // تأمینِ تخفیف (COUPON_FUNDING_2026-09-15): کوپنِ ۵ میلیونیِ این تست حالا
        // تأمین‌کننده‌ای است (سقفِ شرکت روی این سفارش چند صد هزار ریال است)، پس
        // کیف پولِ تأمین‌کننده باید لِگش را بپوشاند.
        Wallet::create([Wallet::USER_ID => $supplier->id, Wallet::BALANCE => 50_000_000, Wallet::BALANCE_HOLD => 0]);
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

        FactoryStock::updateOrCreate([
            'product_variant_id' => $this->variant->id,
            'factory_id' => $this->factory->id,
            'factory_location_id' => $this->location->id,
        ], ['quantity' => 100_000]);

        $this->shipping = ShippingMethod::create([
            ShippingMethod::NAME => 'تست حمل',
            ShippingMethod::CODE => 'SHIP_'.Str::random(6),
        ]);

        $sys = User::factory()->create();
        config(['financial.system_user_id' => $sys->id]);
        // کیف پولِ سازه باید پول داشته باشد: تخفیفِ کوپن یک هزینه‌ی بازاریابیِ
        // سازه است (`debitSystem(coupon)` پیش از `debitSystem(PTS)`)، و چون PTS
        // تقریباً برابرِ POO است، با موجودیِ صفر همان لِگ `InsufficientBalance`
        // می‌داد — در تولید این کیف پول هرگز خالی نیست.
        Wallet::create([Wallet::USER_ID => $sys->id, Wallet::BALANCE => 500_000_000, Wallet::BALANCE_HOLD => 0]);

        // کیف پولِ خریدار هر دو حالت (با/بی‌تخفیف) را می‌پوشاند تا شاخه همیشه `wallet` باشد.
        Wallet::updateOrCreate(
            [Wallet::USER_ID => $this->buyer->id],
            [Wallet::BALANCE => 500_000_000, Wallet::BALANCE_HOLD => 0]
        );
    }

    private function fillCart(int $kg, ?string $couponCode): Cart
    {
        $unit = OrderUnit::create([
            OrderUnit::NAME => 'کیلوگرم',
            OrderUnit::CODE => 'KG_'.Str::random(6),
            OrderUnit::UNIT => 'kg',
            OrderUnit::AMOUNT => 1,
        ]);

        $cart = Cart::create([Cart::USER_ID => $this->buyer->id]);
        $cart->forceFill([
            Cart::SUPPLIER_USER_ID => $this->supplierId,
            Cart::PARKED_UNTIL => now()->addHours(2),
            Cart::SHIPPING_METHOD_ID => $this->shipping->id,
            Cart::COUPON_CODE => $couponCode,
        ])->save();

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

    private function pay(array $extra = [])
    {
        Sanctum::actingAs($this->buyer);

        return $this->postJson('/api/checkout/pay', array_merge([
            'address_id' => Address::create([
                Address::USER_ID => $this->buyer->id,
                Address::TITLE => 'خانه',
                Address::POSTAL_ADDRESS => 'آدرس تست',
                Address::RECEIVER_NAME => 'گیرنده',
                Address::RECEIVER_MOBILE => '09120000000',
            ])->id,
            'shipping_method_id' => $this->shipping->id,
        ], $extra));
    }

    #[Test]
    public function the_coupon_on_the_cart_is_applied_even_when_the_request_body_omits_it(): void
    {
        $coupon = Coupon::create([
            Coupon::CODE => 'CART-'.strtoupper(Str::random(6)),
            Coupon::USER_ID => $this->buyer->id,
            Coupon::TYPE => Coupon::TYPE_FIXED,
            Coupon::AMOUNT => 5_000_000,
        ]);
        // منبعِ تأمین به هدفِ این تست (منبعِ حقیقت = ستونِ سبد) ربطی ندارد؛ فقط
        // باید از سقفِ شرکت عبور نکند، پس تأمین‌کننده‌ی سفارش (بی‌سقف) می‌دهدش.
        $coupon->funders()->create([
            CouponFunder::FUNDER_TYPE => CouponFunder::TYPE_SUPPLIER,
            CouponFunder::FUNDER_USER_ID => $this->supplierId,
            CouponFunder::SHARE_PERCENT => 100,
            CouponFunder::STATUS => CouponFunder::STATUS_ACCEPTED,
            CouponFunder::RESPONDED_AT => now(),
        ]);

        $this->fillCart(100, $coupon->code);

        // عمداً بدونِ `coupon_code` — همان درخواستی که فرانت پیش از رسیدنِ پیش‌نمایش می‌فرستاد.
        $response = $this->pay();
        $response->assertOk();

        $order = Order::where(Order::PLACER_USER_ID, $this->buyer->id)->latest('id')->firstOrFail();

        $this->assertSame($coupon->id, (int) $order->{Order::COUPON_ID});
        $this->assertEqualsWithDelta(5_000_000.0, (float) $order->{Order::COUPON_DISCOUNT_AMOUNT}, 1.0);
    }

    #[Test]
    public function an_elite_yar_funded_coupon_on_a_cart_without_that_elite_yar_is_refused_in_the_preview_too(): void
    {
        $eliteYAR = User::factory()->create();
        Wallet::create([Wallet::USER_ID => $eliteYAR->id, Wallet::BALANCE => 50_000_000, Wallet::BALANCE_HOLD => 0]);

        $coupon = Coupon::create([
            Coupon::CODE => 'EY-'.strtoupper(Str::random(6)),
            Coupon::USER_ID => $this->buyer->id,
            Coupon::TYPE => Coupon::TYPE_PERCENT,
            Coupon::AMOUNT => 100,
        ]);
        $coupon->funders()->create([
            CouponFunder::FUNDER_TYPE => CouponFunder::TYPE_ELITE_YAR,
            CouponFunder::FUNDER_USER_ID => $eliteYAR->id,
            CouponFunder::SHARE_PERCENT => 100,
            CouponFunder::STATUS => CouponFunder::STATUS_ACCEPTED,
            CouponFunder::RESPONDED_AT => now(),
        ]);

        $this->fillCart(10, $coupon->code);
        Sanctum::actingAs($this->buyer);

        // QA 2026-09-15: این‌جا «۰ ریال قابل پرداخت» بود و تازه `pay` رد می‌کرد.
        $data = $this->getJson('/api/checkout/payment-options')->assertOk()->json('data');

        $this->assertSame('0', $data['breakdown']['coupon_discount']);
        $this->assertStringContainsString('الیت‌یار', (string) $data['breakdown']['coupon_error']);
        $this->assertGreaterThan(0, (float) $data['final_amount']);
        $this->assertEqualsWithDelta(
            (float) $data['breakdown']['goods_with_vat'] + (float) $data['breakdown']['shipping_with_vat'],
            (float) $data['final_amount'],
            1.0
        );

        // ثبت هم با همان پیام رد می‌شود — دو مسیر، یک تصمیم.
        $this->pay()->assertStatus(422)->assertJsonPath('message', $data['breakdown']['coupon_error']);

        // و خودِ پیش‌نمایشِ کد (لحظه‌ی واردکردن در overlay) هم — سه مسیر، همان یک تصمیم.
        // تا پیش از این «کد معتبر است» می‌گفت و کد روی سبد می‌نشست.
        $this->postJson('/api/checkout/coupon-preview', ['code' => $coupon->code])
            ->assertStatus(422)
            ->assertJsonPath('message', $data['breakdown']['coupon_error']);
    }

    #[Test]
    public function the_coupon_preview_refuses_another_suppliers_coupon_with_the_placement_message(): void
    {
        $other = User::factory()->create();
        Wallet::create([Wallet::USER_ID => $other->id, Wallet::BALANCE => 50_000_000, Wallet::BALANCE_HOLD => 0]);
        SupplierProfile::create([SupplierProfile::USER_ID => $other->id]);

        $coupon = Coupon::create([
            Coupon::CODE => 'OS-'.strtoupper(Str::random(6)),
            Coupon::USER_ID => $this->buyer->id,
            Coupon::TYPE => Coupon::TYPE_FIXED,
            Coupon::AMOUNT => 1_000_000,
        ]);
        $coupon->funders()->create([
            CouponFunder::FUNDER_TYPE => CouponFunder::TYPE_SUPPLIER,
            CouponFunder::FUNDER_USER_ID => $other->id,
            CouponFunder::SHARE_PERCENT => 100,
            CouponFunder::STATUS => CouponFunder::STATUS_ACCEPTED,
            CouponFunder::RESPONDED_AT => now(),
        ]);

        // سبدِ تأمین‌کننده‌ی این تست (نه `$other`) — کد هنوز روی سبد ننشسته.
        $this->fillCart(10, null);
        Sanctum::actingAs($this->buyer);

        $preview = $this->postJson('/api/checkout/coupon-preview', ['code' => $coupon->code]);
        $preview->assertStatus(422);
        $this->assertStringContainsString('همان تأمین‌کننده', (string) $preview->json('message'));

        // کوپنی که واقعاً مالِ همین سبد است همچنان می‌گذرد.
        $ok = Coupon::create([
            Coupon::CODE => 'MINE-'.strtoupper(Str::random(6)),
            Coupon::USER_ID => $this->buyer->id,
            Coupon::TYPE => Coupon::TYPE_FIXED,
            Coupon::AMOUNT => 1_000_000,
        ]);
        $ok->funders()->create([
            CouponFunder::FUNDER_TYPE => CouponFunder::TYPE_SUPPLIER,
            CouponFunder::FUNDER_USER_ID => $this->supplierId,
            CouponFunder::SHARE_PERCENT => 100,
            CouponFunder::STATUS => CouponFunder::STATUS_ACCEPTED,
            CouponFunder::RESPONDED_AT => now(),
        ]);

        $this->postJson('/api/checkout/coupon-preview', ['code' => $ok->code])
            ->assertOk()
            ->assertJsonPath('data.code', $ok->code);
    }

    /**
     * قرینه‌ی یافته‌ی ۱ در مسیرِ «خرید برای دیگری»: صاحبِ سبد الیت‌یار است و کارفرما
     * می‌پردازد، پس ثبت (`payForBeneficiary` → `PlaceOrderAction`) او را الیت‌یارِ
     * کمیسیون‌گیر می‌شمارد و کوپنِ خودش را کسر می‌کند — ولی پیش‌نمایش با الیت‌یارِ
     * `null` همان کوپن را صفر می‌شمرد؛ کارفرما مبلغِ بدونِ تخفیف می‌دید و ناچار بود
     * بیش از مبلغِ واقعی شارژ کند. پیش‌نمایش حالا الیت‌یار را از خودِ سبد می‌خواند.
     */
    #[Test]
    public function the_preview_counts_the_cart_owner_as_the_elite_yar_only_when_the_cart_is_for_a_beneficiary(): void
    {
        Wallet::where(Wallet::USER_ID, $this->buyer->id)->update([Wallet::BALANCE => 50_000_000]);

        $coupon = Coupon::create([
            Coupon::CODE => 'OWN-'.strtoupper(Str::random(6)),
            Coupon::USER_ID => $this->buyer->id,
            Coupon::TYPE => Coupon::TYPE_FIXED,
            Coupon::AMOUNT => 1_000_000,
        ]);
        $coupon->funders()->create([
            CouponFunder::FUNDER_TYPE => CouponFunder::TYPE_ELITE_YAR,
            CouponFunder::FUNDER_USER_ID => $this->buyer->id,
            CouponFunder::SHARE_PERCENT => 100,
            CouponFunder::STATUS => CouponFunder::STATUS_ACCEPTED,
            CouponFunder::RESPONDED_AT => now(),
        ]);

        $cart = $this->fillCart(10, $coupon->code);
        $totals = app(CartCheckoutTotals::class);

        // خریدِ مستقیم: صاحبِ سبد خودش می‌پردازد ⇒ کمیسیون‌گیری در کار نیست ⇒ رد.
        $direct = $totals->couponFor($cart, $this->buyer, 9_900_000.0);
        $this->assertSame(0.0, $direct['discount']);
        $this->assertStringContainsString('الیت‌یار', (string) $direct['error']);

        // سبدِ لینک‌شده برای کارفرما، ولی صاحبِ سبد نقشِ الیت‌یاری ندارد ⇒ ثبت
        // (`$user->isEliteYAR()`) او را الیت‌یارِ سفارش نمی‌شمارد ⇒ پیش‌نمایش هم نه.
        $cart->forceFill([Cart::BENEFICIARY_USER_ID => User::factory()->create()->id])->save();

        $linkedNoRole = $totals->couponFor($cart->fresh(['items']), $this->buyer, 9_900_000.0);
        $this->assertSame(0.0, $linkedNoRole['discount']);
        $this->assertStringContainsString('الیت‌یار', (string) $linkedNoRole['error']);

        // با نقش ⇒ صاحبِ سبد الیت‌یارِ سفارش است و کارفرما می‌پردازد ⇒ همان تصمیمِ ثبت.
        $this->buyer->addRole(Role::firstOrCreate(['name' => 'engineer'], ['display_name' => 'engineer']));

        $forEmployer = $totals->couponFor($cart->fresh(['items', 'user']), $this->buyer, 9_900_000.0);
        $this->assertSame(1_000_000.0, $forEmployer['discount']);
        $this->assertNull($forEmployer['error']);
    }

    #[Test]
    public function a_cart_without_a_coupon_is_still_placed_without_one(): void
    {
        $this->fillCart(100, null);

        $this->pay()->assertOk();

        $order = Order::where(Order::PLACER_USER_ID, $this->buyer->id)->latest('id')->firstOrFail();

        $this->assertNull($order->{Order::COUPON_ID});
        $this->assertSame(0.0, (float) $order->{Order::COUPON_DISCOUNT_AMOUNT});
    }
}
