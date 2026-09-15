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
use App\Models\Setting;
use App\Models\ShippingMethod;
use App\Models\SupplierProfile;
use App\Models\User;
use App\Models\Wallet;
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
    public function a_cart_without_a_coupon_is_still_placed_without_one(): void
    {
        $this->fillCart(100, null);

        $this->pay()->assertOk();

        $order = Order::where(Order::PLACER_USER_ID, $this->buyer->id)->latest('id')->firstOrFail();

        $this->assertNull($order->{Order::COUPON_ID});
        $this->assertSame(0.0, (float) $order->{Order::COUPON_DISCOUNT_AMOUNT});
    }
}
