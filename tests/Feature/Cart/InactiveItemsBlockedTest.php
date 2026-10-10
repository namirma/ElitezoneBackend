<?php

declare(strict_types=1);

namespace Tests\Feature\Cart;

use App\Models\Address;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\FactoryStock;
use App\Models\OrderUnit;
use App\Models\Product;
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
use Laratrust\Models\Role;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Pricing\CreatesPricingFixtures;
use Tests\TestCase;

/**
 * کالای غیرفعال (محصول یا واریانت) نه به سبد اضافه می‌شود نه سفارش داده می‌شود (علی ۱۴۰۵/۰۷/۱۹).
 * BE2 فقط پنهانش کرده بود؛ شناسه از یک تبِ باز یا سبدِ قدیمی هنوز می‌رسید. قلمِ غیرفعالِ مانده
 * در سبد حذف نمی‌شود — در پاسخِ سبد پرچم می‌خورد تا سایت صادقانه نشانش دهد.
 */
class InactiveItemsBlockedTest extends TestCase
{
    use CreatesPricingFixtures, DatabaseTransactions;

    private User $supplier;

    private $factory;

    private $location;

    private Product $product;

    private ProductVariant $variant;

    private OrderUnit $unit;

    private ShippingMethod $shipping;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        $this->supplier = User::factory()->create();
        $this->supplier->addRole(Role::firstOrCreate(['name' => 'supplier'], ['display_name' => 'Supplier']));
        Wallet::create([Wallet::USER_ID => $this->supplier->id, Wallet::BALANCE => 0, Wallet::BALANCE_HOLD => 0]);
        SupplierProfile::create([SupplierProfile::USER_ID => $this->supplier->id])
            ->forceFill([
                SupplierProfile::STATUS => SupplierProfile::STATUS_APPROVED,
                SupplierProfile::RESERVE_TIME => now()->addDays(3),
            ])->save();

        $this->factory = $this->makeFactory();
        $this->factory->forceFill(['supplier_user_id' => $this->supplier->id])->save();
        $this->location = $this->makeLocation($this->factory);
        $this->product = $this->makeProduct();
        $this->variant = $this->makeVariant($this->product);
        $this->makePrice($this->variant, $this->factory, $this->location, general: 1_000_000, special: 900_000);

        $this->unit = OrderUnit::create([
            OrderUnit::NAME => 'کیلوگرم', OrderUnit::CODE => 'KG_'.Str::random(6), OrderUnit::UNIT => 'kg', OrderUnit::AMOUNT => 1,
        ]);
        $this->product->orderUnits()->attach($this->unit->id, ['max_quantity' => 100_000]);
        $this->shipping = ShippingMethod::create([ShippingMethod::NAME => 'حمل', ShippingMethod::CODE => 'SHIP_'.Str::random(6)]);
        FactoryStock::updateOrCreate([
            'product_variant_id' => $this->variant->id,
            'factory_id' => $this->factory->id,
            'factory_location_id' => $this->location->id,
        ], ['quantity' => 5000]);
    }

    private function addToCart()
    {
        return $this->postJson('/api/cart/items', [
            'product_variant_id' => $this->variant->id,
            'factory_id' => $this->factory->id,
            'location_id' => $this->location->id,
            'shipping_method_id' => $this->shipping->id,
            'quantities' => [(string) $this->unit->id => 10],
        ]);
    }

    private function cartHoldingTheVariant(User $buyer): Cart
    {
        $cart = Cart::create([Cart::USER_ID => $buyer->id]);
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
            CartItem::QUANTITIES => [(string) $this->unit->id => 100],
            CartItem::WEIGHT => 100,
            CartItem::QUANTITY => 100,
            CartItem::UNIT_NAME => 'کیلوگرم',
            CartItem::PRICE => 900_000,
            CartItem::FINAL_PRICE => 900_000,
            CartItem::VAT_AMOUNT => 90_000,
            CartItem::TOTAL_WITH_VAT => 99_000_000,
        ]);

        return $cart;
    }

    #[Test]
    public function an_active_variant_is_still_added(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->addToCart()->assertOk();
    }

    #[Test]
    public function an_inactive_variant_is_refused_at_add_to_cart(): void
    {
        $this->variant->update([ProductVariant::IS_ACTIVE => false]);
        Sanctum::actingAs($buyer = User::factory()->create());

        $this->addToCart()->assertStatus(422)->assertJsonPath('message', __('cart.item_unavailable_add'));
        $this->assertDatabaseMissing('cart_items', [CartItem::PRODUCT_VARIANT_ID => $this->variant->id]);
    }

    #[Test]
    public function a_variant_of_an_inactive_product_is_refused_at_add_to_cart(): void
    {
        $this->product->update([Product::IS_ACTIVE => false]);
        Sanctum::actingAs(User::factory()->create());

        $this->addToCart()->assertStatus(422)->assertJsonPath('message', __('cart.item_unavailable_add'));
    }

    #[Test]
    public function the_cart_flags_an_inactive_line_and_keeps_it(): void
    {
        $buyer = User::factory()->create();
        $this->cartHoldingTheVariant($buyer);
        Sanctum::actingAs($buyer);

        $line = $this->getJson('/api/cart')->assertOk()->json('data.cart.items.0');
        $this->assertTrue($line['is_available']);
        $this->assertNull($line['unavailable_reason']);

        $this->product->update([Product::IS_ACTIVE => false]);

        $line = $this->getJson('/api/cart')->assertOk()->json('data.cart.items.0');
        $this->assertFalse($line['is_available']);
        $this->assertSame(__('cart.item_unavailable'), $line['unavailable_reason']);
    }

    #[Test]
    public function checkout_refuses_a_cart_holding_an_inactive_line(): void
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

        $this->cartHoldingTheVariant($buyer);
        $this->variant->update([ProductVariant::IS_ACTIVE => false]);   // بعد از نشستن در سبد

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
            'shipping_method_id' => $this->shipping->id,
        ])->assertStatus(422)->assertJsonPath('message', __('orders.errors.cart_has_unavailable_items'));

        $this->assertDatabaseMissing('orders', ['placer_user_id' => $buyer->id]);
    }
}
