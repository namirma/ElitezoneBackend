<?php

namespace Tests\Feature\Cart;

use App\Models\Address;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Invoice;
use App\Models\OrderUnit;
use App\Models\ShippingMethod;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Pricing\CreatesPricingFixtures;
use Tests\TestCase;

/**
 * GET /api/cart/pre-invoice — پیش‌فاکتور از سبدِ هنوز ثبت‌نشده.
 *
 * سه چیز پین می‌شود، چون هر سه دقیقاً همان‌هایی‌اند که این اندپوینت برای نبودنشان
 * ساخته شده:
 *   ۱. سند بدونِ ساختِ سفارش صادر می‌شود (هیچ ردیفی به `orders` اضافه نمی‌شود)
 *   ۲. هیچ رکورد `Invoice`ای ساخته نمی‌شود — نه شماره‌ای مصرف می‌شود، نه آرشیوی
 *   ۳. سبد بعد از دانلود دست‌نخورده می‌ماند (برخلافِ ثبتِ سفارش که سبد را خالی می‌کند)
 *
 * DatabaseTransactions: همهٔ نوشتن‌ها rollback می‌شوند.
 */
class CartPreInvoiceTest extends TestCase
{
    use CreatesPricingFixtures;
    use DatabaseTransactions;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    /** سبدِ کاربر با یک ردیفِ ۱۰۰ کیلویی به قیمتِ ۹۰۰,۰۰۰ ریال (بدون مالیات). */
    private function cartWithOneLine(): Cart
    {
        Cache::flush();
        $this->setVatRate(10);

        $factory = $this->makeFactory();
        $location = $this->makeLocation($factory);
        $variant = $this->makeVariant();
        $this->makePrice($variant, $factory, $location, general: 1_000_000, special: 900_000);

        $unit = OrderUnit::create([
            OrderUnit::NAME => 'کیلوگرم',
            OrderUnit::CODE => 'KG_'.Str::random(4),
            OrderUnit::UNIT => 'kg',
            OrderUnit::AMOUNT => 1,
        ]);

        $shipping = ShippingMethod::create([
            ShippingMethod::NAME => 'تست حمل',
            ShippingMethod::CODE => 'SHIP_'.Str::random(4),
        ]);

        $cart = Cart::create([
            Cart::USER_ID => $this->user->id,
            Cart::SESSION_ID => (string) Str::uuid(),
        ]);

        $cart->items()->create([
            CartItem::PRODUCT_VARIANT_ID => $variant->id,
            CartItem::FACTORY_ID => $factory->id,
            CartItem::FACTORY_LOCATION_ID => $location->id,
            CartItem::SHIPPING_METHOD_ID => $shipping->id,
            CartItem::QUANTITIES => [(string) $unit->id => 100],
            CartItem::WEIGHT => 100,
            CartItem::QUANTITY => 100,
            CartItem::UNIT_NAME => 'کیلوگرم',
            CartItem::PRICE => 900_000,
            CartItem::FINAL_PRICE => 900_000,
            CartItem::VAT_AMOUNT => 90_000,
            CartItem::TOTAL_WITH_VAT => 99_000_000,
            CartItem::WITH_VAT => true,
        ]);

        Cache::flush();

        return $cart;
    }

    #[Test]
    public function a_guest_cannot_download_a_pre_invoice(): void
    {
        // سندِ مالی است — مسیر پشت auth:sanctum است، پس مهمان ۴۰۱ می‌گیرد.
        $this->getJson('/api/cart/pre-invoice')->assertStatus(401);
    }

    #[Test]
    public function an_empty_cart_is_rejected_instead_of_producing_a_blank_pdf(): void
    {
        Sanctum::actingAs($this->user);

        $this->getJson('/api/cart/pre-invoice')->assertStatus(400);
    }

    #[Test]
    public function it_rejects_an_address_the_user_does_not_own(): void
    {
        $this->cartWithOneLine();

        $stranger = User::factory()->create();
        $foreign = Address::create([
            Address::USER_ID => $stranger->id,
            Address::TITLE => 'خانه',
            Address::POSTAL_ADDRESS => 'آدرس تست',
            Address::RECEIVER_NAME => 'گیرنده',
            Address::RECEIVER_MOBILE => '09120000000',
        ]);

        Sanctum::actingAs($this->user);

        $this->getJson('/api/cart/pre-invoice?address_id='.$foreign->id)->assertStatus(403);
    }

    #[Test]
    public function it_streams_a_pdf_without_creating_an_order_or_an_invoice_record(): void
    {
        $cart = $this->cartWithOneLine();

        $ordersBefore = \App\Models\Order::count();
        $invoicesBefore = Invoice::count();

        Sanctum::actingAs($this->user);

        $response = $this->get('/api/cart/pre-invoice');

        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('content-type'));

        $this->assertSame($ordersBefore, \App\Models\Order::count(),
            'پیش‌فاکتور نباید سفارش بسازد — کلِ نکتهٔ این اندپوینت همین است');
        $this->assertSame($invoicesBefore, Invoice::count(),
            'پیش‌فاکتور نباید رکورد Invoice بسازد و شمارهٔ سند مصرف کند');

        $this->assertSame(1, $cart->items()->count(),
            'سبد باید دست‌نخورده بماند — برخلاف ثبت سفارش که سبد را خالی می‌کند');
    }
}
