<?php

declare(strict_types=1);

namespace Tests\Feature\Checkout;

use App\Models\Address;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\FactoryStock;
use App\Models\OrderUnit;
use App\Models\ProductVariant;
use App\Models\Setting;
use App\Models\ShippingMethod;
use App\Models\ShippingRate;
use App\Models\SupplierProfile;
use App\Models\User;
use App\Models\Wallet;
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

        if (class_exists(FactoryStock::class)) {
            FactoryStock::updateOrCreate([
                'product_variant_id' => $this->variant->id,
                'factory_id' => $this->factory->id,
                'factory_location_id' => $this->location->id,
            ], ['quantity' => 100_000]);
        } else {
            $this->variant->forceFill([ProductVariant::STOCK_QUANTITY => 100_000])->save();
        }

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
}
