<?php

declare(strict_types=1);

namespace Tests\Feature\Financial;

use App\Actions\Financial\ProcessWeighbridgeResultAction;
use App\Constants\FinancialCodes;
use App\Models\Address;
use App\Models\Badge;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\FactoryStock;
use App\Models\Order;
use App\Models\OrderItem;
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
use App\Services\Invoice\InvoiceDataBuilder;
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

    // ── دورِ پنجم، پرسشِ ۱: خریدِ خودِ الیت‌یار ───────────────────────────────

    /**
     * «الیت‌یاری که برای خودش می‌خرد دو تخفیف می‌گیرد: همان تخفیفِ کارفرمای دارای الیت‌یار، و
     * تخفیفی برابر با سهمِ الیت‌یار با عنوانِ "تخفیف ویژه الیت یار"؛ کارمزدی پرداخت نمی‌شود.»
     */
    #[Test]
    public function a_verified_elite_yar_buying_for_themself_gets_both_discounts_and_no_commission(): void
    {
        $eliteYAR = User::factory()->create([User::IS_SAZE90_USER => true]);
        $eliteYAR->addRole(Role::firstOrCreate(['name' => 'elite_yar'], ['display_name' => 'elite_yar']));
        Wallet::create([Wallet::USER_ID => $eliteYAR->id, Wallet::BALANCE => 500_000_000, Wallet::BALANCE_HOLD => 0]);
        $this->fillCart($eliteYAR);

        Sanctum::actingAs($eliteYAR);
        $preview = $this->getJson('/api/checkout/payment-options')->assertOk()->json('data.breakdown');

        $before = $this->balance($eliteYAR);
        $this->pay($eliteYAR)->assertOk();
        $order = $this->lastOrderOf($eliteYAR);

        // %TC ۱٪ ⇒ OW = ۰٫۴٪ و CO (پیش‌گام، ۲۵٪ از TC) = ۰٫۲۵٪.
        $gross = self::KG * self::SUPPLIER_PRICE * 1.1;
        $this->assertSame($eliteYAR->id, (int) $order->{Order::ELITE_YAR_USER_ID});
        $this->assertEqualsWithDelta($gross * 0.004, (float) $order->{Order::BUYER_DISCOUNT_AMOUNT}, 1.0, 'تخفیف (سهمِ خریدار)');
        $this->assertEqualsWithDelta($gross * 0.0025, (float) $order->{Order::ELITE_YAR_DISCOUNT_AMOUNT}, 1.0, 'تخفیف ویژه الیت یار');
        $this->assertEqualsWithDelta($gross * (1 - 0.0065), (float) $order->{Order::FINAL_AMOUNT}, 1.0);
        $this->assertEqualsWithDelta(0.65, (float) $order->{Order::OWNER_DISCOUNT_RATE}, 0.001, '%OWِ اعمال‌شده = OW + CO');

        // اجزا دقیقاً به جمع می‌رسند و دقیقاً همان کسر شده.
        $components = (float) $order->subtotal - (float) $order->discount_amount + (float) $order->vat_amount
            + (float) $order->shipping_cost - (float) $order->{Order::BUYER_DISCOUNT_AMOUNT}
            - (float) $order->{Order::ELITE_YAR_DISCOUNT_AMOUNT};
        $this->assertEqualsWithDelta($components, (float) $order->{Order::FINAL_AMOUNT}, 0.0);
        $this->assertEqualsWithDelta((float) $order->{Order::FINAL_AMOUNT}, $before - $this->balance($eliteYAR), 0.0);

        // پیش‌نمایش همان دو سطر را گفته بود.
        $this->assertEqualsWithDelta((float) $order->{Order::BUYER_DISCOUNT_AMOUNT}, (float) $preview['buyer_discount'], 1.0);
        $this->assertEqualsWithDelta((float) $order->{Order::ELITE_YAR_DISCOUNT_AMOUNT}, (float) $preview['elite_yar_discount'], 1.0);

        // باسکول با وزنِ یکسان: بی‌کسری، و هیچ کارمزدی به خودِ الیت‌یار نمی‌رسد.
        $afterPay = $this->balance($eliteYAR);
        $order->forceFill([Order::STATUS => Order::STATUS_SHIPPED])->save();
        $result = app(ProcessWeighbridgeResultAction::class)->execute($order->fresh(), (float) self::KG);
        $this->assertSame('equal', $result['status']);
        $this->assertEqualsWithDelta(0.0, (float) $order->fresh()->{Order::PAYMENT_TO_ELITE_YAR}, 0.0);
        $this->assertEqualsWithDelta($afterPay, $this->balance($eliteYAR), 0.0);
        $this->assertSame(0.0, (float) Wallet::where(Wallet::USER_ID, $eliteYAR->id)->value(Wallet::BALANCE_HOLD));
    }

    #[Test]
    public function an_employer_with_an_elite_yar_gets_no_elite_yar_special_discount(): void
    {
        $buyer = $this->buyer(withEliteYAR: true);
        $this->fillCart($buyer);

        $this->pay($buyer)->assertOk();

        $this->assertSame(0.0, (float) $this->lastOrderOf($buyer)->{Order::ELITE_YAR_DISCOUNT_AMOUNT});
    }

    // ── دورِ پنجم، پرسشِ ۷: اسنادِ تأمین‌کننده ───────────────────────────────

    /** «مبلغِ قابلِ دریافتِ تأمین‌کننده (پس از کارمزد)؛ مبلغِ خریدار را نبیند.» */
    #[Test]
    public function supplier_documents_show_the_supplier_receivable_and_never_the_buyer_amounts(): void
    {
        // فیلترِ +۲۰٬۰۰۰ ریال/کیلو: قیمتِ خریدار با قیمتِ تأمین‌کننده فرق دارد.
        $this->addRoleFilter(20_000);
        $buyer = $this->buyer(withEliteYAR: true);
        $this->fillCart($buyer);
        $this->pay($buyer)->assertOk();
        $order = $this->lastOrderOf($buyer)->load('items');

        $this->assertEqualsWithDelta(self::SUPPLIER_PRICE, (float) $order->items->first()->{OrderItem::SUPPLIER_UNIT_PRICE}, 0.0001);

        $builder = app(InvoiceDataBuilder::class);
        $supplierDoc = $builder->build($order, FinancialCodes::DOC_REPORT_SUPPLIER, 'T-1');
        $buyerDoc = $builder->build($order, FinancialCodes::DOC_RECEIPT_OWNER, 'T-2');
        $pts = (float) $order->{Order::PAYMENT_TO_SUPPLIER};

        $this->assertTrue($supplierDoc['supplier_view']);
        $this->assertSame('مبلغ قابل دریافت:', $supplierDoc['payable_label']);
        $this->assertEqualsWithDelta($pts, (float) $supplierDoc['totals']['final'], 0.0, 'مبلغِ قابلِ دریافت = PTS');
        $this->assertEqualsWithDelta(self::SUPPLIER_PRICE, (float) $supplierDoc['line_items'][0]['unit_price'], 0.0001, 'قیمتِ خودِ تأمین‌کننده');
        $this->assertSame(0.0, (float) $supplierDoc['totals']['buyer_discount']);
        $this->assertSame(0.0, (float) $supplierDoc['totals']['coupon_discount']);

        // جمع تا ریال: کالا (با مالیات) + کرایه − کارمزد = مبلغِ قابلِ دریافت؛ کارمزد ≈ ۱٪ از کالا با مالیات.
        $t = $supplierDoc['totals'];
        $this->assertEqualsWithDelta($t['final'], $t['goods_total'] + $t['shipping'] - $t['commission'], 0.0);
        $this->assertEqualsWithDelta(self::KG * self::SUPPLIER_PRICE * 1.1 * 0.01, (float) $t['commission'], 2.0);

        // سندِ خریدار دست نخورده: قیمتِ خریدار و مبلغِ قابلِ پرداختِ خودش.
        $this->assertFalse($buyerDoc['supplier_view']);
        $this->assertEqualsWithDelta(self::SUPPLIER_PRICE + 20_000, (float) $buyerDoc['line_items'][0]['unit_price'], 1.0);
        $this->assertEqualsWithDelta((float) $order->{Order::FINAL_AMOUNT}, (float) $buyerDoc['totals']['final'], 0.0);
        $this->assertNotEquals($buyerDoc['amount_words'], $supplierDoc['amount_words']);
    }

    // ── دورِ ششم، پرسشِ ۸: اسنادِ الیت‌یار ─────────────────────────────────────

    /**
     * «مبلغِ پرداختیِ خریدار در هیچ جا به الیت‌یار یا تأمین‌کننده نشان نده.» سندِ D (گزارشِ الیت‌یار)
     * «مبلغ سفارش» را نشان می‌داد و هر دو سندِ D و Q کارمزد را با ضریبِ ثابتِ `final × 0.006` حساب
     * می‌کردند — نه با فرمولِ واقعی (سهمِ CO از استخر). Q باید همان مبلغی باشد که به الیت‌یار پرداخت شد.
     */
    #[Test]
    public function elite_yar_documents_show_the_real_commission_and_never_the_buyer_amount(): void
    {
        $buyer = $this->buyer(withEliteYAR: true);
        $this->fillCart($buyer);
        $this->pay($buyer)->assertOk();
        $order = $this->lastOrderOf($buyer)->load('items');
        $this->assertNotNull($order->{Order::ELITE_YAR_USER_ID});

        // تخمینِ پیش از باسکول: استخر × CO/(CO+SSG) — پیش‌گام: ۲۵ / (۲۵ + ۳۵).
        $pool = (float) $order->{Order::PAYMENT_OF_OWNER} - (float) $order->{Order::PAYMENT_TO_SUPPLIER};
        $report = app(InvoiceDataBuilder::class)->build($order, FinancialCodes::DOC_ELITE_YAR_REPORT, 'T-D');
        $this->assertTrue($report['elite_yar_view']);
        $this->assertEqualsWithDelta($pool * 25 / 60, (float) $report['totals']['final'], 1.0);
        $this->assertNotEqualsWithDelta((float) $order->{Order::FINAL_AMOUNT} * 0.006, (float) $report['totals']['final'], 1.0);
        $this->assertArrayNotHasKey('gross', $report['totals'], 'هیچ مبلغی از خریدار در سندِ الیت‌یار نیست');

        // سفارشِ قدیمی (بی‌اسنپ‌شاتِ سهم) با نشانِ الیت‌یار قیمت می‌خورد — طلایی: ۳۷٫۵ / (۳۷٫۵ + ۲۲٫۵).
        $gold = Badge::firstOrCreate([Badge::CODE => 'gold'], [Badge::NAME => 'Gold']);
        User::findOrFail($order->{Order::ELITE_YAR_USER_ID})->forceFill([User::BADGE_ID => $gold->id])->save();
        // `fresh()`، نه `replicate()`: replicate رابطه‌ی eliteYARِ بی‌نشانِ بارشده را هم کپی می‌کند. ذخیره نمی‌شود.
        $legacy = $order->fresh()->forceFill([Order::OW_SHARE_PERCENT => null, Order::CO_SHARE_PERCENT => null]);
        $this->assertEqualsWithDelta($pool * 37.5 / 60, app(InvoiceDataBuilder::class)->eliteYARCommission($legacy), 1.0);

        // پس از باسکول: سندِ Q همان کارمزدی است که واقعاً ثبت شد.
        $order->forceFill([Order::STATUS => Order::STATUS_SHIPPED])->save();
        app(ProcessWeighbridgeResultAction::class)->execute($order->fresh(), (float) self::KG);
        $order = $order->fresh()->load('items');
        $tax = app(InvoiceDataBuilder::class)->build($order, FinancialCodes::DOC_TAX_INVOICE_ELITE_YAR, 'T-Q');
        $this->assertGreaterThan(0.0, (float) $order->{Order::PAYMENT_TO_ELITE_YAR});
        $this->assertEqualsWithDelta((float) $order->{Order::PAYMENT_TO_ELITE_YAR}, (float) $tax['totals']['final'], 0.0);
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
    public function a_role_discount_is_ignored_and_the_buyer_price_stays_on_the_supplier_price(): void
    {
        // مدیریت، دورِ پنجم: کف = قیمتِ اعلامیِ تأمین‌کننده؛ تخفیفِ نقش‌محور اصلاً اعمال نمی‌شود
        // و تنها تخفیفِ خریدار سطرِ جدای «تخفیف ویژه» است.
        $this->addRoleFilter(-50_000);
        $buyer = $this->buyer(withEliteYAR: true);
        $this->fillCart($buyer);

        $this->pay($buyer)->assertOk();

        $order = $this->lastOrderOf($buyer);
        $this->assertEqualsWithDelta(self::SUPPLIER_PRICE, (float) $order->{Order::OFFICIAL_PRICE}, 0.001, 'قیمتِ خریدار = قیمتِ تأمین‌کننده');
        $this->assertEqualsWithDelta(self::SUPPLIER_PRICE, (float) $order->{Order::SUPPLIER_PRICE}, 0.001);
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
