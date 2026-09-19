<?php

namespace Tests\Feature\Checkout;

use App\Actions\Order\PlaceOrderAction;
use App\DataTransferObjects\Order\PlaceOrderData;
use App\Exceptions\BusinessException;
use App\Models\Address;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\ShippingMethod;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Pricing\CreatesPricingFixtures;
use Tests\TestCase;

/**
 * یک سفارش = یک تأمین‌کننده (تا زمان نوشتن تسویه‌ی چندخطی).
 *
 * سبد خرید عمداً چندمبدأی است — کل زیرسیستم Shipment برای همین ساخته شده و
 * AddToCartAction هر factory_id را می‌پذیرد. اما تسویه‌ی مالی یک‌خطی است: هر پنج
 * نقطه‌ی تسویه با `items->first()` کار می‌کنند. پس یک سفارشِ دوتأمین‌کننده‌ای:
 *
 *   • کلِ PTS را به تأمین‌کننده‌ی آیتمِ اول می‌داد و دومی **هیچ** نمی‌گرفت،
 *   • مالیات امانی و بدهیِ کسری را فقط روی اولی ثبت می‌کرد،
 *   • و حتی POO را با `قیمتِ آیتم اول × وزنِ کل` حساب می‌کرد.
 *
 * این تست گاردِ ثبت را پین می‌کند. وقتی تسویه‌ی چندخطی پیاده شد، این تست باید
 * عوض شود (نه حذف) تا توزیعِ درستِ چندتأمین‌کننده را اثبات کند.
 */
class SingleSupplierPerOrderTest extends TestCase
{
    use CreatesPricingFixtures;
    use DatabaseTransactions;

    #[Test]
    public function a_cart_spanning_two_suppliers_is_refused_before_anything_is_written(): void
    {
        $buyer = User::factory()->create();
        $cart = Cart::create([Cart::USER_ID => $buyer->id, Cart::SESSION_ID => null]);

        // دو کارخانه که به دو تأمین‌کننده‌ی *متفاوت* تعلق دارند.
        $supplierA = User::factory()->create();
        $supplierB = User::factory()->create();

        $this->addCartLine($cart, $supplierA);
        $this->addCartLine($cart, $supplierB);

        $data = $this->makeOrderData($buyer);

        try {
            app(PlaceOrderAction::class)->execute($buyer, $data);
            $this->fail('ثبت سفارش با دو تأمین‌کننده باید رد شود.');
        } catch (BusinessException $e) {
            $this->assertSame(__('orders.multiple_suppliers_not_supported'), $e->getMessage());
        }

        // گارد پیش از هر نوشتنی اجرا می‌شود: نه سفارشی ساخته شده و نه سبد خالی شده.
        $this->assertDatabaseMissing('orders', ['placer_user_id' => $buyer->id]);
        $this->assertSame(2, $cart->fresh()->items()->count(), 'سبد کاربر نباید دست‌خورده باشد.');
    }

    #[Test]
    public function two_lines_from_the_same_supplier_pass_the_guard(): void
    {
        $buyer = User::factory()->create();
        $cart = Cart::create([Cart::USER_ID => $buyer->id, Cart::SESSION_ID => null]);

        // دو کارخانه‌ی متفاوت ولی متعلق به *یک* تأمین‌کننده — چندمبدأیِ مجاز.
        $supplier = User::factory()->create();
        $this->addCartLine($cart, $supplier);
        $this->addCartLine($cart, $supplier);

        try {
            app(PlaceOrderAction::class)->execute($buyer, $this->makeOrderData($buyer));
        } catch (BusinessException $e) {
            $this->assertNotSame(
                __('orders.multiple_suppliers_not_supported'),
                $e->getMessage(),
                'سبدِ تک‌تأمین‌کننده نباید به گاردِ چندتأمین‌کننده بخورد.'
            );
        } catch (\Throwable $e) {
            // هر شکستِ دیگری (قیمت/موجودی/…) مربوط به این گارد نیست و اینجا مهم نیست:
            // ادعای این تست فقط «به گاردِ تأمین‌کننده نخورد» است.
            $this->assertTrue(true);
        }
    }

    /**
     * یک خطِ سبد با کارخانه‌ی تازه‌ای که مالکش $supplier است.
     */
    private function addCartLine(Cart $cart, User $supplier): CartItem
    {
        $factory = $this->makeFactory();
        $factory->forceFill(['supplier_user_id' => $supplier->id])->save();

        $location = $this->makeLocation($factory);
        $variant = $this->makeVariant();
        $this->makePrice($variant, $factory, $location);

        return CartItem::create([
            CartItem::CART_ID => $cart->id,
            CartItem::PRODUCT_VARIANT_ID => $variant->id,
            CartItem::FACTORY_ID => $factory->id,
            CartItem::FACTORY_LOCATION_ID => $location->id,
            CartItem::SHIPPING_METHOD_ID => $this->sharedShippingMethod()->id,
            CartItem::QUANTITIES => ['quantity' => 1],
            // price/final_price در اسکیمای cart_items مقدار پیش‌فرض ندارند.
            CartItem::PRICE => 1_000_000,
            CartItem::FINAL_PRICE => 1_000_000,
        ]);
    }

    private function sharedShippingMethod(): ShippingMethod
    {
        return $this->shippingMethod ??= ShippingMethod::create([
            'name' => 'پست پیشتاز',
            'code' => 'TEST_'.uniqid(),
        ]);
    }

    private ?ShippingMethod $shippingMethod = null;

    private function makeOrderData(User $buyer): PlaceOrderData
    {
        $address = Address::create([
            Address::USER_ID => $buyer->id,
            Address::POSTAL_ADDRESS => 'تهران، خیابان آزادی، پلاک ۱',
            Address::RECEIVER_NAME => 'علی محمدی',
            Address::RECEIVER_MOBILE => '09121111111',
        ]);

        $shipping = $this->sharedShippingMethod();

        return new PlaceOrderData(
            beneficiaryUserId: null,
            eliteYARUserId: null,
            addressId: $address->id,
            shippingMethodId: $shipping->id,
            description: null,
            couponCode: null,
            ipAddress: '127.0.0.1',
            userAgent: 'phpunit',
        );
    }
}
