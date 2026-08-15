<?php

namespace Tests\Feature\Order;

use App\Models\Address;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\FactoryStock;
use App\Models\Order;
use App\Models\OrderUnit;
use App\Models\ProductVariant;
use App\Models\Setting;
use App\Models\ShippingMethod;
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
 * موجودی در لحظه‌ی **پرداخت** کسر می‌شود، نه در لحظه‌ی ساختن سفارش — بند ۶.۲.
 *
 * چرا این تست بحرانی است (§۱۳.۲): تا پیش از بازطراحی، `PlaceOrderAction`
 * موجودی را همان لحظه‌ی ثبت سفارش کسر می‌کرد در حالی که هیچ پولی جابه‌جا نشده
 * بود. یعنی هر کسی می‌توانست با ثبت سفارش و نپرداختن، کالای انبار را برای دو
 * ساعت از دسترس بقیه خارج کند — یک DoS رایگان روی موجودی.
 */
class StockDeductedAtPaymentTest extends TestCase
{
    use CreatesPricingFixtures;
    use DatabaseTransactions;

    private const STOCK = 5000.0;

    private User $user;

    private $factory;

    private $location;

    private $variant;

    private ShippingMethod $shipping;

    protected function setUp(): void
    {
        parent::setUp();

        // سندهای PDF در محیط تست وجود ندارند و تسویه آن‌ها را می‌سازد. متدِ
        // خصوصیِ `generate()` قابل mock نیست، پس متدهای عمومیِ صادرکننده
        // بی‌اثر می‌شوند.
        $this->mock(InvoiceGenerator::class, function ($mock) {
            $mock->shouldReceive('generateDirectPurchaseDocs')->andReturnNull();
            $mock->shouldReceive('generateReservationDocs')->andReturnNull();
        });

        Setting::updateOrCreate([Setting::KEY => 'shaparak_value'], [Setting::VALUE => '2000000000']);
        Setting::updateOrCreate([Setting::KEY => 'reserve_value'], [Setting::VALUE => '2000000000']);
        Cache::forget(Setting::CACHE_KEY);

        $this->user = User::factory()->create();

        // کارخانه باید تأمین‌کننده داشته باشد: تسویه سهمِ PTS را به کیف پول
        // همان کاربر واریز می‌کند و بدونش ثبت سفارش رد می‌شود.
        $supplier = User::factory()->create();
        Wallet::create([Wallet::USER_ID => $supplier->id, Wallet::BALANCE => 0, Wallet::BALANCE_HOLD => 0]);

        $this->factory = $this->makeFactory();
        $this->factory->forceFill(['supplier_user_id' => $supplier->id])->save();
        $this->location = $this->makeLocation($this->factory);
        $this->variant = $this->makeVariant();
        $this->makePrice($this->variant, $this->factory, $this->location, general: 1_000_000, special: 900_000);

        $this->setStock(self::STOCK);

        $this->shipping = ShippingMethod::create([
            ShippingMethod::NAME => 'تست حمل',
            ShippingMethod::CODE => 'SHIP_'.Str::random(6),
        ]);

        $sys = User::factory()->create();
        config(['financial.system_user_id' => $sys->id]);
        Wallet::create([Wallet::USER_ID => $sys->id, Wallet::BALANCE => 0, Wallet::BALANCE_HOLD => 0]);
    }

    /**
     * موجودی از همان جایی خوانده می‌شود که `setStock` می‌نویسد.
     *
     * شاخه‌ی L10 موجودیِ per-origin دارد (`factory_stocks`) و شاخه‌ی L13 هنوز
     * روی خودِ واریانت نگه می‌دارد. آنچه این تست پین می‌کند «کِی» کسر می‌شود
     * است نه «کجا»، پس هر دو شاخه با یک فایل کار می‌کنند.
     */
    private function stockNow(): float
    {
        if (class_exists(FactoryStock::class)) {
            return (float) FactoryStock::where('product_variant_id', $this->variant->id)
                ->where('factory_location_id', $this->location->id)
                ->value('quantity');
        }

        return (float) $this->variant->fresh()->{ProductVariant::STOCK_QUANTITY};
    }

    private function setStock(float $quantity): void
    {
        if (class_exists(FactoryStock::class)) {
            FactoryStock::updateOrCreate(
                [
                    'product_variant_id' => $this->variant->id,
                    'factory_id' => $this->factory->id,
                    'factory_location_id' => $this->location->id,
                ],
                ['quantity' => $quantity],
            );

            return;
        }

        $this->variant->forceFill([ProductVariant::STOCK_QUANTITY => $quantity])->save();
    }

    private function fillCart(float $kg = 100): Cart
    {
        $unit = OrderUnit::create([
            OrderUnit::NAME => 'کیلوگرم',
            OrderUnit::CODE => 'KG_'.Str::random(6),
            OrderUnit::UNIT => 'kg',
            OrderUnit::AMOUNT => 1,
        ]);

        $cart = Cart::create([Cart::USER_ID => $this->user->id]);
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

        Cache::flush();

        return $cart->fresh();
    }

    private function fundWallet(float $amount): void
    {
        Wallet::updateOrCreate(
            [Wallet::USER_ID => $this->user->id],
            [Wallet::BALANCE => $amount, Wallet::BALANCE_HOLD => 0],
        );
    }

    private function address(): int
    {
        return Address::create([
            Address::USER_ID => $this->user->id,
            Address::TITLE => 'خانه',
            Address::POSTAL_ADDRESS => 'آدرس تست',
            Address::RECEIVER_NAME => 'گیرنده',
            Address::RECEIVER_MOBILE => '09120000000',
        ])->id;
    }

    #[Test]
    public function filling_the_cart_does_not_touch_stock(): void
    {
        $this->fillCart();

        $this->assertSame(self::STOCK, $this->stockNow(), 'سبد نباید موجودی را کم کند.');
        $this->assertSame(0, Order::where(Order::PLACER_USER_ID, $this->user->id)->count(),
            'سبد نباید هیچ رکورد سفارشی بسازد.');
    }

    #[Test]
    public function paying_deducts_stock_and_creates_the_order(): void
    {
        $this->fillCart();
        $this->fundWallet(500_000_000);
        Sanctum::actingAs($this->user);

        $this->postJson('/api/checkout/pay', [
            'address_id' => $this->address(),
            'shipping_method_id' => $this->shipping->id,
        ])->assertOk();

        $this->assertSame(self::STOCK - 100, $this->stockNow());

        $order = Order::where(Order::PLACER_USER_ID, $this->user->id)->firstOrFail();
        $this->assertSame(Order::STATUS_PROCESSING, $order->status);
        $this->assertSame(Order::PAYMENT_PAID, $order->payment_status);
    }

    #[Test]
    public function reserving_deducts_stock(): void
    {
        // رزرو برخلافِ سبد واقعاً کالا را کنار می‌گذارد (بند ۳.۴).
        $this->fillCart();
        $this->fundWallet(3_000_000_000);
        Sanctum::actingAs($this->user);

        $this->postJson('/api/checkout/reserve', [
            'address_id' => $this->address(),
            'shipping_method_id' => $this->shipping->id,
        ])->assertOk();

        $this->assertSame(self::STOCK - 100, $this->stockNow());
        $this->assertSame(
            Order::STATUS_RESERVED,
            Order::where(Order::PLACER_USER_ID, $this->user->id)->value(Order::STATUS),
        );
    }

    #[Test]
    public function a_failed_payment_leaves_stock_and_creates_nothing(): void
    {
        // بند ۳.۳: «پرداخت ناتمام ⇒ هیچ سفارشی ساخته نمی‌شود، سبد دست‌نخورده.»
        $this->fillCart();
        $this->fundWallet(1_000); // خیلی کمتر از مبلغ سفارش
        Sanctum::actingAs($this->user);

        $this->postJson('/api/checkout/pay', [
            'address_id' => $this->address(),
            'shipping_method_id' => $this->shipping->id,
        ])->assertStatus(422);

        $this->assertSame(self::STOCK, $this->stockNow(), 'پرداخت ناموفق نباید موجودی را کم کند.');
        $this->assertSame(0, Order::where(Order::PLACER_USER_ID, $this->user->id)->count(),
            'پرداخت ناموفق نباید سفارشی بسازد.');
        $this->assertNotNull(Cart::queryForUser($this->user->id)->first(), 'سبد باید دست‌نخورده بماند.');
    }

    #[Test]
    public function paying_more_than_the_remaining_stock_is_refused_and_nothing_moves(): void
    {
        // بین نمایش سبد و پرداخت، کالا ممکن است تمام شود. کلِ تراکنش باید
        // برگردد و پول در کیف پول کاربر بماند (بند ۳.۳).
        $this->fillCart(kg: 100);
        $this->fundWallet(500_000_000);

        $this->setStock(10);

        Sanctum::actingAs($this->user);

        $this->postJson('/api/checkout/pay', [
            'address_id' => $this->address(),
            'shipping_method_id' => $this->shipping->id,
        ])->assertStatus(422);

        $this->assertSame(10.0, $this->stockNow());
        $this->assertSame(0, Order::where(Order::PLACER_USER_ID, $this->user->id)->count());
        $this->assertSame(500_000_000.0, (float) Wallet::where(Wallet::USER_ID, $this->user->id)->value(Wallet::BALANCE),
            'پول باید دست‌نخورده در کیف پول بماند.');
    }
}
