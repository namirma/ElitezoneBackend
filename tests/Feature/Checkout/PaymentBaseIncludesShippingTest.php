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
use App\Models\ProductPrice;
use App\Models\Setting;
use App\Models\ShippingMethod;
use App\Models\ShippingRate;
use App\Models\SupplierProfile;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Invoice\InvoiceGenerator;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Pricing\CreatesPricingFixtures;
use Tests\TestCase;

/**
 * پایه‌ی درختِ تصمیم باید همان عددی باشد که از کیف پول کم می‌شود.
 *
 * `CartPricingCalculator` کرایه را `'0.0000'` هاردکد می‌کند، پس
 * `payment-options` روی عددِ **بدونِ کرایه** تصمیم می‌گرفت — در حالی که
 * `PlaceOrderAction` می‌نویسد
 * `final_amount = subtotal − discount + totalVat + shippingCost`.
 *
 * دو پیامد، و دومی بدتر است:
 *
 *   ۱. `gateway_amount` به‌اندازه‌ی کرایه کم بود؛ کاربری که دقیقاً همان را شارژ
 *      می‌کرد، سرِ پرداخت باز هم کسری داشت.
 *   ۲. **خودِ `branch` می‌توانست غلط باشد** — کسی که موجودی‌اش کالا را
 *      می‌پوشاند ولی کالا+کرایه را نه، شاخه‌ی `wallet` می‌گرفت و دکمه‌ای
 *      می‌دید که قطعاً ۴۲۲ می‌گیرد. دقیقاً همان چیزی که بند ۶.۱ («منبع واحد»)
 *      برای جلوگیری از آن نوشته شده.
 */
class PaymentBaseIncludesShippingTest extends TestCase
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
        // ماژولِ مشارکتِ تأمین‌کننده/الیت‌یار در کوپن از دورِ ششم (۱۴۰۵/۰۷/۱۵) خاموش است («فقط شرکت کوپن
        // صادر می‌کند»)؛ کد مانده (قانونِ یک) و این تست‌ها با پرچمِ روشن سالم نگهش می‌دارند.
        config(['features.partner_coupons' => true]);

        Setting::updateOrCreate([Setting::KEY => 'shaparak_value'], [Setting::VALUE => '2000000000']);
        Setting::updateOrCreate([Setting::KEY => 'reserve_value'], [Setting::VALUE => '2000000000']);
        Cache::forget(Setting::CACHE_KEY);

        $this->buyer = User::factory()->create();

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

        FactoryStock::updateOrCreate([
            'product_variant_id' => $this->variant->id,
            'factory_id' => $this->factory->id,
            'factory_location_id' => $this->location->id,
        ], ['quantity' => 100_000]);

        $this->shipping = ShippingMethod::create([
            ShippingMethod::NAME => 'تست حمل',
            ShippingMethod::CODE => 'SHIP_'.Str::random(6),
        ]);
    }

    private function address(): Address
    {
        // استانِ واقعی لازم است (FK) و نرخِ حمل هم با همین شناسه پیدا می‌شود؛
        // آدرسِ بدونِ استان یعنی هیچ نرخی مطابقت نمی‌کند و کرایه صفر می‌ماند —
        // همان حالتی که این تست می‌خواهد ردش کند.
        $provinceId = (int) (DB::table('province')->value('id')
            ?? DB::table('province')->insertGetId(['name' => 'استان تست']));

        return Address::create([
            Address::USER_ID => $this->buyer->id,
            // استان لازم است: نرخِ حمل با `destination_province_id` پیدا می‌شود،
            // و آدرسِ بدونِ استان یعنی هیچ نرخی مطابقت نمی‌کند و کرایه صفر
            // می‌ماند — که همان چیزی است که این تست می‌خواهد ردش کند.
            Address::PROVINCE_ID => $provinceId,
            Address::TITLE => 'خانه',
            Address::POSTAL_ADDRESS => 'آدرس تست',
            Address::RECEIVER_NAME => 'گیرنده',
            Address::RECEIVER_MOBILE => '09120000000',
        ]);
    }

    private function fillCart(Address $address, int $kg = 100): Cart
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
            Cart::ADDRESS_ID => $address->id,
        ])->save();

        $cart->items()->create([
            CartItem::PRODUCT_VARIANT_ID => $this->variant->id,
            CartItem::FACTORY_ID => $this->factory->id,
            CartItem::FACTORY_LOCATION_ID => $this->location->id,
            CartItem::SHIPPING_METHOD_ID => $this->shipping->id,
            CartItem::ADDRESS_ID => $address->id,
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

    private function addFreight(Address $address, float $flat): void
    {
        ShippingRate::create([
            'factory_location_id' => $this->location->id,
            'destination_province_id' => (int) $address->province_id,
            'destination_city_id' => null,
            'flat_cost' => $flat,
            'per_kg_cost' => 0,
            'is_active' => true,
        ]);
    }

    private function branchOptions(): array
    {
        Sanctum::actingAs($this->buyer);

        return $this->getJson('/api/checkout/payment-options')->assertOk()->json('data');
    }

    #[Test]
    public function the_decision_base_carries_the_freight(): void
    {
        $address = $this->address();
        $this->fillCart($address);
        $this->addFreight($address, 50_000_000);

        $data = $this->branchOptions();

        $this->assertTrue($data['breakdown']['shipping_included']);
        $this->assertGreaterThan(0.0, (float) $data['breakdown']['shipping_with_vat']);

        // پایه باید کالا + کرایه باشد، نه فقط کالا.
        $this->assertSame(
            (float) $data['breakdown']['goods_with_vat'] + (float) $data['breakdown']['shipping_with_vat'],
            (float) $data['final_amount']
        );
    }

    /**
     * QA 2026-09-15 یافته‌ی ۲ (قرینه): پس از گردکردنِ `final_amount`، اجزای همان
     * پاسخ (`goods_with_vat`، `shipping_with_vat`) اعشاری می‌ماندند و جمعِ اجزا
     * با خودِ `final_amount` نمی‌خواند. نرخ‌های حمل ریالِ صحیح‌اند ولی وزنِ
     * لجستیکی = تعداد × ضریبِ واحد، و ضریب می‌تواند اعشاری باشد («شاخه» = ۱٫۵
     * کیلو): «۱٬۲۳۳ ریال × ۱۰٫۵ کیلو» کرایه‌ی اعشاری می‌سازد که عیناً به پایه
     * می‌رسید — همه‌ی اعداد باید روی سیم ریالِ صحیح و جمع‌پذیر باشند.
     */
    #[Test]
    public function every_breakdown_figure_on_the_wire_is_whole_rials_and_adds_up(): void
    {
        $address = $this->address();
        $cart = $this->fillCart($address, 7);
        OrderUnit::whereKey(array_key_first($cart->items->first()->{CartItem::QUANTITIES}))
            ->update([OrderUnit::AMOUNT => 1.5]);
        ShippingRate::create([
            'factory_location_id' => $this->location->id,
            'destination_province_id' => (int) $address->province_id,
            'destination_city_id' => null,
            'flat_cost' => 0,
            'per_kg_cost' => 1_233,
            'is_active' => true,
        ]);

        $data = $this->branchOptions();
        $b = $data['breakdown'];

        foreach (['goods_with_vat', 'shipping_with_vat', 'coupon_discount'] as $key) {
            $this->assertSame(0.0, fmod((float) $b[$key], 1.0), "{$key} باید ریالِ صحیح باشد، نه {$b[$key]}");
        }
        $this->assertSame(0.0, fmod((float) $data['final_amount'], 1.0));
        $this->assertSame(
            (float) $b['goods_with_vat'] + (float) $b['shipping_with_vat'] - (float) $b['coupon_discount'],
            (float) $data['final_amount']
        );
    }

    /**
     * همان پایه‌ی اعشاری، این بار تا **ثبتِ سفارش**: `calculateOrderFinancials` floatِ خام
     * می‌داد و MySQL هر ستون را جدا گرد می‌کرد — «Subtotal − Discount + Tax + Shipping =
     * Final» روی ردیفِ ذخیره‌شده تا ۱ ریال نمی‌خواند و کوپنِ درصدی روی پایه‌ی اعشاری
     * حساب می‌شد. حالا یک بار در انتهای همان تابع گرد می‌شود و ردیف با خودش می‌خواند.
     */
    #[Test]
    public function the_placed_order_stores_whole_rials_that_add_up(): void
    {
        $this->mock(InvoiceGenerator::class, function ($mock) {
            $mock->shouldReceive('generateDirectPurchaseDocs')->andReturnNull();
            $mock->shouldReceive('generateReservationDocs')->andReturnNull();
        });
        $sys = User::factory()->create();
        config(['financial.system_user_id' => $sys->id]);
        Wallet::create([Wallet::USER_ID => $sys->id, Wallet::BALANCE => 500_000_000, Wallet::BALANCE_HOLD => 0]);
        Wallet::updateOrCreate([Wallet::USER_ID => $this->buyer->id], [Wallet::BALANCE => 500_000_000, Wallet::BALANCE_HOLD => 0]);

        $coupon = Coupon::create([
            Coupon::CODE => 'PCT-'.strtoupper(Str::random(6)),
            Coupon::USER_ID => $this->buyer->id,
            Coupon::TYPE => Coupon::TYPE_PERCENT,
            Coupon::AMOUNT => 10,
        ]);
        $coupon->funders()->create([
            CouponFunder::FUNDER_TYPE => CouponFunder::TYPE_SUPPLIER,
            CouponFunder::FUNDER_USER_ID => $this->supplierId,
            CouponFunder::SHARE_PERCENT => 100,
            CouponFunder::STATUS => CouponFunder::STATUS_ACCEPTED,
            CouponFunder::RESPONDED_AT => now(),
        ]);
        Wallet::where(Wallet::USER_ID, $this->supplierId)->update([Wallet::BALANCE => 50_000_000]);

        $address = $this->address();
        $cart = $this->fillCart($address, 7);
        $cart->forceFill([Cart::COUPON_CODE => $coupon->code])->save();
        // اعدادِ عمداً بدقلق: ضریبِ واحد ۱٫۲۵ (وزن ۸٫۷۵ کیلو)، قیمتِ ۱٬۰۰۰٬۰۰۷ و کرایه‌ی
        // ۱٬۱۱۱ ریال/کیلو ⇒ کسرِ هر جزء زیرِ ۰٫۵ است ولی کسرِ جمعشان بالای ۰٫۵ — یعنی
        // اگر MySQL هر ستون را جدا گرد کند، مجموعِ ستون‌ها یک ریال از `final_amount`
        // کمتر می‌شود (پیش از رفع دقیقاً همین می‌شد).
        OrderUnit::whereKey(array_key_first($cart->items->first()->{CartItem::QUANTITIES}))
            ->update([OrderUnit::AMOUNT => 1.25]);
        ProductPrice::query()
            ->where('product_variant_id', $this->variant->id)
            ->where('factory_id', $this->factory->id)
            ->update([
                ProductPrice::GENERAL_PRICE => 1_000_007,
                ProductPrice::SPECIAL_PRICE => 900_007,
                ProductPrice::APPROVED_GENERAL_PRICE => 1_000_007,
                ProductPrice::APPROVED_SPECIAL_PRICE => 900_007,
            ]);
        ShippingRate::create([
            'factory_location_id' => $this->location->id,
            'destination_province_id' => (int) $address->province_id,
            'destination_city_id' => null,
            'flat_cost' => 0,
            'per_kg_cost' => 1_111,
            'is_active' => true,
        ]);

        Sanctum::actingAs($this->buyer);
        $this->postJson('/api/checkout/pay', [
            'address_id' => $address->id,
            'shipping_method_id' => $this->shipping->id,
        ])->assertOk();

        $row = DB::table('orders')->where(Order::PLACER_USER_ID, $this->buyer->id)->latest('id')->first();
        $this->assertNotNull($row);

        foreach (['subtotal', 'discount_amount', 'vat_amount', 'shipping_cost', 'coupon_discount_amount', 'final_amount', 'official_price'] as $column) {
            $this->assertSame(0.0, fmod((float) $row->{$column}, 1.0), "orders.{$column} باید ریالِ صحیح باشد، شد {$row->{$column}}");
        }
        $this->assertGreaterThan(0.0, (float) $row->shipping_cost);
        $this->assertGreaterThan(0.0, (float) $row->coupon_discount_amount);
        // هویتِ حسابداری روی خودِ ردیف — بدونِ گردکردنِ پیش از نوشتن، تا ۱ ریال نمی‌خواند.
        $this->assertSame(
            (float) $row->subtotal - (float) $row->discount_amount + (float) $row->vat_amount + (float) $row->shipping_cost - (float) $row->coupon_discount_amount,
            (float) $row->final_amount
        );

        $item = DB::table('order_items')->where('order_id', $row->id)->first();
        foreach (['unit_price', 'total_price', 'shipping_cost'] as $column) {
            $this->assertSame(0.0, fmod((float) $item->{$column}, 1.0), "order_items.{$column} باید ریالِ صحیح باشد، شد {$item->{$column}}");
        }
    }

    #[Test]
    public function the_branch_flips_when_the_wallet_covers_goods_but_not_freight(): void
    {
        // ⚠️ بدترین پیامد، و همان چیزی که این تست برای گرفتنش هست: کاربری که
        // موجودی‌اش دقیقاً کالا را می‌پوشاند، پیش از این شاخه‌ی `wallet` می‌گرفت
        // و دکمه‌ای می‌دید که سرِ پرداخت قطعاً ۴۲۲ می‌شد.
        $address = $this->address();
        $this->fillCart($address);
        $this->addFreight($address, 50_000_000);

        $goodsOnly = $this->branchOptions()['breakdown']['goods_with_vat'];

        Wallet::updateOrCreate(
            [Wallet::USER_ID => $this->buyer->id],
            [Wallet::BALANCE => (float) $goodsOnly, Wallet::BALANCE_HOLD => 0]
        );

        $data = $this->branchOptions();

        $this->assertSame('gateway', $data['branch'], 'موجودی کالا را می‌پوشاند ولی کرایه را نه.');
        $this->assertGreaterThan(0.0, (float) $data['gateway_amount']);
    }

    #[Test]
    public function a_cart_with_no_destination_says_so_instead_of_pretending(): void
    {
        // پایه‌ی ناقص که **می‌گوید** ناقص است، بهتر از عددی که وانمود می‌کند کامل است.
        $cart = Cart::create([Cart::USER_ID => $this->buyer->id]);
        $cart->forceFill([
            Cart::SUPPLIER_USER_ID => $this->supplierId,
            Cart::PARKED_UNTIL => now()->addHours(2),
            Cart::SHIPPING_METHOD_ID => $this->shipping->id,
        ])->save();

        $unit = OrderUnit::create([
            OrderUnit::NAME => 'کیلوگرم',
            OrderUnit::CODE => 'KG_'.Str::random(6),
            OrderUnit::UNIT => 'kg',
            OrderUnit::AMOUNT => 1,
        ]);

        $cart->items()->create([
            CartItem::PRODUCT_VARIANT_ID => $this->variant->id,
            CartItem::FACTORY_ID => $this->factory->id,
            CartItem::FACTORY_LOCATION_ID => $this->location->id,
            CartItem::SHIPPING_METHOD_ID => $this->shipping->id,
            CartItem::QUANTITIES => [(string) $unit->id => 100],
            CartItem::WEIGHT => 100,
            CartItem::QUANTITY => 100,
            CartItem::UNIT_NAME => 'کیلوگرم',
            CartItem::PRICE => 900_000,
            CartItem::FINAL_PRICE => 900_000,
            CartItem::VAT_AMOUNT => 90_000,
            CartItem::TOTAL_WITH_VAT => 99_000_000,
        ]);

        $data = $this->branchOptions();

        $this->assertFalse($data['breakdown']['shipping_included']);
        $this->assertSame(0.0, (float) $data['breakdown']['shipping_with_vat']);
    }

    // ── دو پرچمِ ممیزیِ ۲۰۲۶-۰۹-۱۲ (باگ‌های ۱ و ۴) ─────────────────────────────
    // این‌جا و نه در فایلِ جدا، چون همین fixtures کافی‌اند و هر دو پرچم روی همان
    // پاسخِ payment-options می‌نشینند.

    #[Test]
    public function payment_options_reports_whether_the_online_gateway_rail_exists(): void
    {
        $address = $this->address();
        $this->fillCart($address);

        // PASARGAD_* در محیطِ تست خالی است، پس driver پیکربندی‌نشده و رِیل خاموش
        // است — همان وضعیتی که `wallet/deposit` برای podium/shaparak ۵۰۳ می‌دهد.
        $data = $this->branchOptions();

        $this->assertArrayHasKey('online_gateway_active', $data);
        $this->assertFalse($data['online_gateway_active']);
        $this->assertFalse($data['is_for_beneficiary']);
    }

    #[Test]
    public function payment_options_flags_a_cart_that_is_linked_to_an_employer(): void
    {
        $address = $this->address();
        $cart = $this->fillCart($address);

        $employer = User::factory()->create();
        $cart->forceFill([Cart::BENEFICIARY_USER_ID => $employer->id])->save();

        // `pay`/`reserve` روی این سبد ۴۲۲ می‌دهند؛ فرانت باید از همین پرچم بفهمد
        // و کارتِ تسویه را ببندد، نه از یک کلیکِ شکست‌خورده.
        $this->assertTrue($this->branchOptions()['is_for_beneficiary']);
    }
}
