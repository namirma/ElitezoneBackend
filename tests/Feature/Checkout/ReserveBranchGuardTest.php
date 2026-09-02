<?php

namespace Tests\Feature\Checkout;

use App\Models\Address;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Order;
use App\Models\OrderUnit;
use App\Models\ProductVariant;
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
 * رزرو راهِ گریز از ناتوانیِ پرداخت است، نه یک انتخابِ دلخواه.
 *
 * ‏`PaymentBranch` این قاعده را در docblockش می‌نویسد و `reserveAvailable()` را
 * هم برایش دارد — ولی `CheckoutController::reserve()` هرگز صدایش نمی‌زد. تنها
 * چیزی که می‌سنجید «آیا کیف پول بیعانه را پوشش می‌دهد» بود، پس خریداری که در
 * شاخه‌ی الف/ب است و باید کل مبلغ را بپردازد می‌توانست با صدا زدنِ همین
 * اندپوینت سفارشِ `reserved` بگیرد: موجودی کسر و کالا قفل می‌شد و باقیِ پول به
 * بعد می‌افتاد.
 *
 * قرینه‌اش در `pay()` از قبل بود (۴۲۲ برای هر دو شاخه‌ی نادرست)، و همین تقارنِ
 * نیمه‌کاره است که اشتباه را پنهان نگه داشت.
 */
class ReserveBranchGuardTest extends TestCase
{
    use CreatesPricingFixtures;
    use DatabaseTransactions;

    private User $buyer;

    private $factory;

    private $location;

    private $variant;

    private ShippingMethod $shipping;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mock(InvoiceGenerator::class, function ($mock) {
            $mock->shouldReceive('generateDirectPurchaseDocs')->andReturnNull();
            $mock->shouldReceive('generateReservationDocs')->andReturnNull();
        });

        // سقفِ درگاه و بیعانه هر دو ۲ میلیارد ریال — همان پیش‌فرضِ config/sales.
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

        $this->factory = $this->makeFactory();
        $this->factory->forceFill(['supplier_user_id' => $supplier->id])->save();
        $this->location = $this->makeLocation($this->factory);
        $this->variant = $this->makeVariant();
        $this->makePrice($this->variant, $this->factory, $this->location, general: 1_000_000, special: 900_000);
        $this->supplierId = $supplier->id;

        if (class_exists(\App\Models\FactoryStock::class)) {
            \App\Models\FactoryStock::updateOrCreate([
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

        $sys = User::factory()->create();
        config(['financial.system_user_id' => $sys->id]);
        Wallet::create([Wallet::USER_ID => $sys->id, Wallet::BALANCE => 0, Wallet::BALANCE_HOLD => 0]);
    }

    private int $supplierId;

    /** سبدی با ارزشِ تقریبیِ `$kg × 990,000` ریال. */
    private function fillCart(int $kg): Cart
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

    private function fundBuyer(float $amount): void
    {
        Wallet::updateOrCreate(
            [Wallet::USER_ID => $this->buyer->id],
            [Wallet::BALANCE => $amount, Wallet::BALANCE_HOLD => 0]
        );
    }

    private function reserve(): \Illuminate\Testing\TestResponse
    {
        Sanctum::actingAs($this->buyer);

        return $this->postJson('/api/checkout/reserve', [
            'address_id' => Address::create([
                Address::USER_ID => $this->buyer->id,
                Address::TITLE => 'خانه',
                Address::POSTAL_ADDRESS => 'آدرس تست',
                Address::RECEIVER_NAME => 'گیرنده',
                Address::RECEIVER_MOBILE => '09120000000',
            ])->id,
            'shipping_method_id' => $this->shipping->id,
            'confirm_short_window' => true,
        ]);
    }

    #[Test]
    public function a_buyer_who_can_pay_in_full_cannot_reserve_instead(): void
    {
        // سبدِ کوچک (~۹۹ میلیون ریال) و کیف پولی که کاملاً پوششش می‌دهد →
        // شاخه‌ی الف. رزرو نباید در دسترس باشد.
        $this->fillCart(100);
        $this->fundBuyer(5_000_000_000);

        $this->reserve()
            ->assertStatus(422)
            ->assertJsonPath('errors.branch.branch', 'wallet')
            ->assertJsonPath('errors.branch.reserve_available', false);

        $this->assertDatabaseMissing('orders', [
            Order::PLACER_USER_ID => $this->buyer->id,
            Order::STATUS => Order::STATUS_RESERVED,
        ]);
    }

    #[Test]
    public function a_buyer_who_only_needs_a_gateway_top_up_cannot_reserve_either(): void
    {
        // شاخه‌ی ب: کیف پول کافی نیست ولی کسری زیرِ سقفِ درگاه است — مسیرِ درست
        // «اول شارژ کن، بعد بپرداز» است، نه رزرو. کارتِ «با حضور در بانک»
        // دقیقاً همین شاخه است.
        $this->fillCart(1000);           // ~۹۹۰ میلیون ریال
        $this->fundBuyer(2_500_000_000); // بیعانه را پوشش می‌دهد، پس گاردِ قدیمی رد می‌شد

        $this->reserve()
            ->assertStatus(422)
            ->assertJsonPath('errors.branch.reserve_available', false);

        $this->assertDatabaseMissing('orders', [
            Order::PLACER_USER_ID => $this->buyer->id,
            Order::STATUS => Order::STATUS_RESERVED,
        ]);
    }

    #[Test]
    public function a_buyer_beyond_wallet_plus_gateway_can_still_reserve(): void
    {
        // شاخه‌ی ج — تنها جایی که رزرو معنا دارد. گارد نباید مسیرِ درست را ببندد.
        $this->fillCart(10_000);      // ~۹.۹ میلیارد ریال
        $this->fundBuyer(3_000_000_000);

        $this->reserve()->assertOk();

        $this->assertDatabaseHas('orders', [
            Order::PLACER_USER_ID => $this->buyer->id,
            Order::STATUS => Order::STATUS_RESERVED,
        ]);
    }
}
