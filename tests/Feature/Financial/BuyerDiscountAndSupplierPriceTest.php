<?php

declare(strict_types=1);

namespace Tests\Feature\Financial;

use App\Actions\Financial\ProcessWeighbridgeResultAction;
use App\Models\Address;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\FactoryStock;
use App\Models\Order;
use App\Models\OrderUnit;
use App\Models\PriceFilter;
use App\Models\PriceFilterCategory;
use App\Models\PriceFilterType;
use App\Models\PriceSetting;
use App\Models\Role;
use App\Models\Setting;
use App\Models\ShippingMethod;
use App\Models\SupplierProfile;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Invoice\InvoiceGenerator;
use App\Services\PricingRoleResolver;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Pricing\CreatesPricingFixtures;
use Tests\TestCase;

/**
 * پاسخ‌های مدیریت به پرسش‌های ۲، ۴، ۶ و ۷ (۱۴۰۵/۰۷/۱۳):
 *
 *  ۲) مبلغِ فاکتور = مبلغِ پرداختی؛ تخفیفِ ویژه‌ی خریدار یک سطرِ جدا.
 *  ۴) درصدِ کارمزد به ازای قراردادِ هر تأمین‌کننده.
 *  ۶) تأمین‌کننده از قیمتِ ثبت‌شده‌ی **خودش** منهای کارمزد تسویه می‌گیرد؛ فیلترِ قیمت به
 *     حسابِ شرکت است و قیمتِ خریدار زیرِ کفِ قراردادی نمی‌رود.
 *  ۷) تخفیفِ ویژه فقط با الیت‌یار؛ بی‌الیت‌یار هر دو سهم به شرکت می‌رسد.
 *
 * تا پیش از این: %OW به همه داده می‌شد، PTS از قیمتِ خریدار (پس از فیلتر) حساب می‌شد،
 * و `final_amount` بدونِ %OW بود (خریدار عددی می‌دید که کمتر از آن کسر می‌شد —
 * `PooVersusDisplayedAmountTest` همان گپ را مستند کرده بود).
 */
class BuyerDiscountAndSupplierPriceTest extends TestCase
{
    use CreatesPricingFixtures;
    use DatabaseTransactions;

    private const KG = 100;

    private const SUPPLIER_PRICE = 900_000.0;

    private User $supplier;

    private User $system;

    private $factory;

    private $location;

    private $variant;

    private ShippingMethod $shipping;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Queue::fake();
        Http::fake(['*saze90*' => Http::response(null, 503)]);
        $this->mock(InvoiceGenerator::class)->shouldIgnoreMissing();

        Setting::updateOrCreate([Setting::KEY => 'shaparak_value'], [Setting::VALUE => '2000000000']);
        Cache::forget(Setting::CACHE_KEY);
        PriceSetting::updateOrCreate([PriceSetting::CODE => PriceSetting::CODE_TC_PERCENTAGE], [PriceSetting::NAME => 'tc', PriceSetting::VALUE => '1']);
        PriceSetting::updateOrCreate([PriceSetting::CODE => PriceSetting::CODE_OW_SHARE], [PriceSetting::NAME => 'ow', PriceSetting::VALUE => '40']);
        PriceSetting::updateOrCreate([PriceSetting::CODE => PriceSetting::CODE_VAT_RATE], [PriceSetting::NAME => 'vat', PriceSetting::VALUE => '10']);

        $this->supplier = User::factory()->create();
        $this->supplier->addRole(Role::firstOrCreate(['name' => 'supplier'], ['display_name' => 'supplier']));
        Wallet::create([Wallet::USER_ID => $this->supplier->id, Wallet::BALANCE => 0, Wallet::BALANCE_HOLD => 0]);
        SupplierProfile::create([SupplierProfile::USER_ID => $this->supplier->id])
            ->forceFill([SupplierProfile::STATUS => SupplierProfile::STATUS_APPROVED, SupplierProfile::RESERVE_TIME => now()->addDays(3)])
            ->save();

        $this->factory = $this->makeFactory();
        $this->factory->forceFill(['supplier_user_id' => $this->supplier->id])->save();
        $this->location = $this->makeLocation($this->factory);
        $this->variant = $this->makeVariant();
        $this->makePrice($this->variant, $this->factory, $this->location, general: 1_000_000, special: (int) self::SUPPLIER_PRICE);
        FactoryStock::updateOrCreate([
            'product_variant_id' => $this->variant->id,
            'factory_id' => $this->factory->id,
            'factory_location_id' => $this->location->id,
        ], ['quantity' => 100_000]);

        $this->shipping = ShippingMethod::create([ShippingMethod::NAME => 'تست حمل', ShippingMethod::CODE => 'SHIP_'.Str::random(6)]);

        $this->system = User::factory()->create();
        config(['financial.system_user_id' => $this->system->id, 'financial.vat_user_id' => $this->system->id]);
        Wallet::create([Wallet::USER_ID => $this->system->id, Wallet::BALANCE => 500_000_000, Wallet::BALANCE_HOLD => 0]);
    }

    // ── fixtures ────────────────────────────────────────────────────────────

    private function buyer(bool $withEliteYAR): User
    {
        $buyer = User::factory()->create();
        $buyer->addRole(Role::firstOrCreate(['name' => 'employer'], ['display_name' => 'employer']));
        Wallet::create([Wallet::USER_ID => $buyer->id, Wallet::BALANCE => 500_000_000, Wallet::BALANCE_HOLD => 0]);

        if ($withEliteYAR) {
            $eliteYAR = User::factory()->create([User::IS_SAZE90_USER => true]);
            $eliteYAR->addRole(Role::firstOrCreate(['name' => 'elite_yar'], ['display_name' => 'elite_yar']));
            Wallet::create([Wallet::USER_ID => $eliteYAR->id, Wallet::BALANCE => 0, Wallet::BALANCE_HOLD => 0]);
            $buyer->technicalEliteYARs()->attach($eliteYAR->id, ['status' => 'approved']);
        }

        return $buyer;
    }

    private function fillCart(User $owner): Cart
    {
        $unit = OrderUnit::create([OrderUnit::NAME => 'کیلوگرم', OrderUnit::CODE => 'KG_'.Str::random(6), OrderUnit::UNIT => 'kg', OrderUnit::AMOUNT => 1]);

        $cart = Cart::create([Cart::USER_ID => $owner->id]);
        $cart->forceFill([
            Cart::SUPPLIER_USER_ID => $this->supplier->id,
            Cart::PARKED_UNTIL => now()->addHours(2),
            Cart::SHIPPING_METHOD_ID => $this->shipping->id,
        ])->save();
        $cart->items()->create([
            CartItem::PRODUCT_VARIANT_ID => $this->variant->id,
            CartItem::FACTORY_ID => $this->factory->id,
            CartItem::FACTORY_LOCATION_ID => $this->location->id,
            CartItem::SHIPPING_METHOD_ID => $this->shipping->id,
            CartItem::QUANTITIES => [(string) $unit->id => self::KG],
            CartItem::WEIGHT => self::KG,
            CartItem::QUANTITY => self::KG,
            CartItem::UNIT_NAME => 'کیلوگرم',
            CartItem::PRICE => self::SUPPLIER_PRICE,
            CartItem::FINAL_PRICE => self::SUPPLIER_PRICE,
            CartItem::VAT_AMOUNT => self::SUPPLIER_PRICE / 10,
            CartItem::TOTAL_WITH_VAT => self::KG * self::SUPPLIER_PRICE * 1.1,
        ]);

        return $cart->fresh(['items']);
    }

    private function pay(User $buyer, array $extra = [])
    {
        Sanctum::actingAs($buyer);

        return $this->postJson('/api/checkout/pay', array_merge([
            'address_id' => Address::create([
                Address::USER_ID => $buyer->id,
                Address::TITLE => 'خانه',
                Address::POSTAL_ADDRESS => 'آدرس تست',
                Address::RECEIVER_NAME => 'گیرنده',
                Address::RECEIVER_MOBILE => '09120000000',
            ])->id,
            'shipping_method_id' => $this->shipping->id,
        ], $extra));
    }

    private function lastOrderOf(User $buyer): Order
    {
        return Order::where(Order::PLACER_USER_ID, $buyer->id)->latest('id')->firstOrFail();
    }

    private function balance(User $user): float
    {
        return (float) Wallet::where(Wallet::USER_ID, $user->id)->value(Wallet::BALANCE);
    }

    /** یک فیلترِ مطلق روی گروهِ «با الیت‌یار» — همان گروهی که خریدارِ دارای الیت‌یار می‌گیرد. */
    private function addRoleFilter(float $amount): void
    {
        $category = PriceFilterCategory::firstOrCreate(
            [PriceFilterCategory::CODE => PricingRoleResolver::WITH_ELITE_YAR],
            [PriceFilterCategory::NAME => 'با الیت‌یار', 'is_active' => true]
        );
        $type = PriceFilterType::create(['name' => 'special', 'code' => 'T_'.Str::random(5), 'apply_on' => 'special']);
        $filter = PriceFilter::create([
            'price_filter_type_id' => $type->id,
            'name' => 'فیلتر تست',
            'code' => 'F_'.Str::random(6),
            'amount' => $amount,
            'is_percentage' => false,
            'is_active' => true,
            'sort_order' => 0,
        ]);
        $category->filters()->attach($filter->id);
        Cache::forget('pricing:categories_with_filters');
    }

    // ── پرسش‌های ۲ و ۷ ───────────────────────────────────────────────────────

    #[Test]
    public function with_an_elite_yar_the_buyer_discount_is_a_separate_line_and_the_total_is_exactly_what_is_charged(): void
    {
        $buyer = $this->buyer(withEliteYAR: true);
        $this->fillCart($buyer);
        $before = $this->balance($buyer);

        $this->pay($buyer)->assertOk();

        $order = $this->lastOrderOf($buyer);
        $goods = self::KG * self::SUPPLIER_PRICE;              // ۹۰٬۰۰۰٬۰۰۰
        $gross = $goods * 1.1;                                  // ۹۹٬۰۰۰٬۰۰۰
        $expectedDiscount = $gross * 0.004;                     // %OW = ۱٪ × ۴۰٪ = ۰٫۴٪ → ۳۹۶٬۰۰۰

        $this->assertEqualsWithDelta($expectedDiscount, (float) $order->{Order::BUYER_DISCOUNT_AMOUNT}, 1.0, 'سطرِ تخفیفِ ویژه‌ی خریدار');
        $this->assertEqualsWithDelta($gross - $expectedDiscount, (float) $order->{Order::FINAL_AMOUNT}, 1.0, 'مبلغِ نهایی پس از تخفیفِ ویژه');

        // اجزا دقیقاً به جمع می‌رسند: subtotal − discount + vat + shipping − buyer_discount = final.
        $components = (float) $order->subtotal - (float) $order->discount_amount + (float) $order->vat_amount
            + (float) $order->shipping_cost - (float) $order->{Order::BUYER_DISCOUNT_AMOUNT};
        $this->assertEqualsWithDelta($components, (float) $order->{Order::FINAL_AMOUNT}, 0.0);

        // و **دقیقاً** همان مبلغ از کیف پول کم شده — نه یک ریال کمتر یا بیشتر (پرسشِ ۲).
        $this->assertEqualsWithDelta((float) $order->{Order::FINAL_AMOUNT}, $before - $this->balance($buyer), 0.0);
        $this->assertEqualsWithDelta((float) $order->{Order::FINAL_AMOUNT}, (float) $order->{Order::PAYMENT_OF_OWNER}, 0.0);
        $this->assertEqualsWithDelta(0.4, (float) $order->{Order::OWNER_DISCOUNT_RATE}, 0.001);
    }

    #[Test]
    public function without_an_elite_yar_there_is_no_buyer_discount_and_the_weighbridge_does_not_invent_a_deficit(): void
    {
        $buyer = $this->buyer(withEliteYAR: false);
        $this->fillCart($buyer);
        $before = $this->balance($buyer);

        $this->pay($buyer)->assertOk();

        $order = $this->lastOrderOf($buyer);
        $gross = self::KG * self::SUPPLIER_PRICE * 1.1;

        $this->assertSame(0.0, (float) $order->{Order::BUYER_DISCOUNT_AMOUNT});
        $this->assertSame(0.0, (float) $order->{Order::OWNER_DISCOUNT_RATE}, '%OW اعمال‌شده صفر — پرسشِ ۷');
        $this->assertEqualsWithDelta($gross, (float) $order->{Order::FINAL_AMOUNT}, 1.0);
        $this->assertEqualsWithDelta((float) $order->{Order::FINAL_AMOUNT}, $before - $this->balance($buyer), 0.0);

        // تخفیفِ داده‌نشده نزدِ شرکت می‌ماند: استخرِ کمیسیون = POO − PTS شاملِ سهمِ OW هم هست.
        $pool = (float) $order->{Order::PAYMENT_OF_OWNER} - (float) $order->{Order::PAYMENT_TO_SUPPLIER};
        $this->assertEqualsWithDelta($gross * 0.01, $pool, 2.0, 'کلِ ۱٪ کارمزد در استخر است، نه ۰٫۶٪');

        // باسکول با وزنِ یکسان: هیچ کسری/اضافه‌ای — %OW صفرِ اسنپ‌شات دوباره اعمال نمی‌شود.
        $order->forceFill([Order::STATUS => Order::STATUS_SHIPPED])->save();
        $result = app(ProcessWeighbridgeResultAction::class)->execute($order->fresh(), (float) self::KG);
        $this->assertSame('equal', $result['status']);
    }

    #[Test]
    public function the_checkout_preview_shows_the_same_buyer_discount_line(): void
    {
        $buyer = $this->buyer(withEliteYAR: true);
        $this->fillCart($buyer);
        Sanctum::actingAs($buyer);

        $preview = $this->getJson('/api/checkout/payment-options')->assertOk()->json('data.breakdown');

        $this->assertTrue($preview['has_elite_yar_discount']);
        $this->assertEqualsWithDelta(self::KG * self::SUPPLIER_PRICE * 1.1 * 0.004, (float) $preview['buyer_discount'], 1.0);

        $this->pay($buyer)->assertOk();
        $this->assertEqualsWithDelta(
            (float) $preview['buyer_discount'],
            (float) $this->lastOrderOf($buyer)->{Order::BUYER_DISCOUNT_AMOUNT},
            1.0,
            'پیش‌نمایش و سفارش یک عدد می‌گویند'
        );

        $plain = $this->buyer(withEliteYAR: false);
        $this->fillCart($plain);
        Sanctum::actingAs($plain);
        $preview = $this->getJson('/api/checkout/payment-options')->assertOk()->json('data.breakdown');
        $this->assertFalse($preview['has_elite_yar_discount']);
        $this->assertSame('0', $preview['buyer_discount']);
    }

    // ── پرسش‌های ۴ و ۶ ───────────────────────────────────────────────────────

    #[Test]
    public function the_supplier_is_settled_on_their_own_price_and_a_role_markup_goes_to_the_company(): void
    {
        // فیلترِ +۲۰٬۰۰۰ ریال/کیلو روی گروهِ «با الیت‌یار»: خریدار بیشتر می‌پردازد، تأمین‌کننده نه.
        $this->addRoleFilter(20_000);
        $buyer = $this->buyer(withEliteYAR: true);
        $this->fillCart($buyer);

        $this->pay($buyer)->assertOk();

        $order = $this->lastOrderOf($buyer);
        $this->assertEqualsWithDelta(self::SUPPLIER_PRICE, (float) $order->{Order::SUPPLIER_PRICE}, 0.0001, 'قیمتِ ثبت‌شده‌ی تأمین‌کننده');
        $this->assertEqualsWithDelta(self::SUPPLIER_PRICE + 20_000, (float) $order->{Order::OFFICIAL_PRICE}, 0.0001, 'قیمتِ خریدار پس از فیلتر');

        // PTS = قیمتِ خودِ تأمین‌کننده × وزن × (۱ − TC) × (۱ + VAT) — فیلتر در آن نیست (پرسشِ ۶).
        $expectedPts = self::SUPPLIER_PRICE * self::KG * 0.99 * 1.1;
        $this->assertEqualsWithDelta($expectedPts, (float) $order->{Order::PAYMENT_TO_SUPPLIER}, 1.0);
        $this->assertEqualsWithDelta($expectedPts, $this->balance($this->supplier), 1.0, 'کیف پولِ تأمین‌کننده');

        // افزایشِ قیمت (۲۰٬۰۰۰ × ۱۰۰ کیلو، با مالیات) در استخرِ شرکت نشسته است.
        $pool = (float) $order->{Order::PAYMENT_OF_OWNER} - (float) $order->{Order::PAYMENT_TO_SUPPLIER};
        $this->assertGreaterThan(20_000 * self::KG * 1.1, $pool);
    }

    #[Test]
    public function a_role_discount_cannot_push_the_buyer_price_below_the_supplier_contract_floor(): void
    {
        // −۵۰٬۰۰۰ ریال/کیلو (۵٫۵٪) خیلی بیشتر از کلِ کارمزد (۱٪) است → به کف برمی‌گردد.
        $this->addRoleFilter(-50_000);
        $buyer = $this->buyer(withEliteYAR: true);
        $this->fillCart($buyer);

        $this->pay($buyer)->assertOk();

        $order = $this->lastOrderOf($buyer);
        $floor = self::SUPPLIER_PRICE * 0.99 / 0.996;
        $this->assertEqualsWithDelta($floor, (float) $order->{Order::OFFICIAL_PRICE}, 0.001, 'قیمتِ خریدار روی کفِ قراردادی');
        // تأمین‌کننده همچنان قیمتِ کاملِ خودش منهای کارمزد را می‌گیرد؛ استخر ≥ ۰.
        $this->assertGreaterThanOrEqual(
            (float) $order->{Order::PAYMENT_TO_SUPPLIER},
            (float) $order->{Order::PAYMENT_OF_OWNER}
        );
    }

    #[Test]
    public function a_supplier_contract_commission_overrides_the_global_rate(): void
    {
        SupplierProfile::where(SupplierProfile::USER_ID, $this->supplier->id)
            ->first()->forceFill([SupplierProfile::COMMISSION_PERCENT => 3.0])->save();

        $buyer = $this->buyer(withEliteYAR: true);
        $this->fillCart($buyer);

        $this->pay($buyer)->assertOk();

        $order = $this->lastOrderOf($buyer);
        $this->assertEqualsWithDelta(3.0, (float) $order->{Order::COMMISSION_RATE}, 0.001, 'اسنپ‌شاتِ %TC قراردادی');
        $this->assertEqualsWithDelta(1.2, (float) $order->{Order::OWNER_DISCOUNT_RATE}, 0.001, '%OW = ۳٪ × ۴۰٪');
        $this->assertEqualsWithDelta(
            self::SUPPLIER_PRICE * self::KG * 0.97 * 1.1,
            (float) $order->{Order::PAYMENT_TO_SUPPLIER},
            1.0,
            'تأمین‌کننده ۳٪ کارمزد می‌دهد'
        );
        $this->assertEqualsWithDelta(
            self::SUPPLIER_PRICE * self::KG * 1.1 * 0.012,
            (float) $order->{Order::BUYER_DISCOUNT_AMOUNT},
            1.0,
            'تخفیفِ خریدار هم از همان قرارداد'
        );
    }
}
