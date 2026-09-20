<?php

namespace Tests\Feature\Cart;

use App\Exceptions\BusinessException;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\ShippingMethod;
use App\Models\User;
use App\Services\Cart\CartParkService;
use App\Services\Cart\CartResolver;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Pricing\CreatesPricingFixtures;
use Tests\TestCase;

/**
 * قفلِ تک‌تأمین‌کننده روی سبد — بند ۳.۱.
 *
 * این قاعده از قبل وجود داشت ولی فقط در **لحظه‌ی ثبت سفارش**
 * (`PlaceOrderAction::assertSingleSupplier`). یعنی کاربر می‌توانست سبدی از دو
 * تأمین‌کننده بسازد، تا صفحه‌ی پرداخت برود و آنجا رد شود. حالا همان لحظه‌ی
 * افزودن گفته می‌شود.
 */
class SingleSupplierCartTest extends TestCase
{
    use CreatesPricingFixtures;
    use DatabaseTransactions;

    private function service(): CartParkService
    {
        return app(CartParkService::class);
    }

    #[Test]
    public function the_first_item_locks_the_cart_to_its_supplier(): void
    {
        $supplier = User::factory()->create();
        $cart = Cart::create([Cart::USER_ID => User::factory()->create()->id]);

        $this->service()->lockSupplier($cart, $supplier->id);

        $this->assertSame($supplier->id, $cart->fresh()->{Cart::SUPPLIER_USER_ID});
    }

    #[Test]
    public function a_second_supplier_is_refused_with_422(): void
    {
        $first = User::factory()->create();
        $second = User::factory()->create();
        $cart = Cart::create([Cart::USER_ID => User::factory()->create()->id]);

        $this->service()->lockSupplier($cart, $first->id);

        try {
            $this->service()->assertSupplierAllowed($cart->fresh(), $second->id);
            $this->fail('افزودن کالای تأمین‌کننده‌ی دوم باید رد شود.');
        } catch (BusinessException $e) {
            // کدِ HTTP روی `$httpCode` است نه `getCode()`، و اکسپشن خودش
            // render می‌شود — پس همان چیزی سنجیده می‌شود که کلاینت می‌گیرد.
            $this->assertSame(422, $e->render(request())->getStatusCode());
            // پیام باید بگوید چه کار کند، نه فقط اینکه نمی‌شود.
            $this->assertStringContainsString('یک تأمین‌کننده', $e->getMessage());
        }
    }

    #[Test]
    public function adding_more_of_the_same_supplier_is_allowed(): void
    {
        $supplier = User::factory()->create();
        $cart = Cart::create([Cart::USER_ID => User::factory()->create()->id]);

        $this->service()->lockSupplier($cart, $supplier->id);
        $this->service()->assertSupplierAllowed($cart->fresh(), $supplier->id);

        $this->assertSame($supplier->id, $cart->fresh()->{Cart::SUPPLIER_USER_ID});
    }

    #[Test]
    public function resolving_an_empty_cart_drops_a_stale_supplier_lock(): void
    {
        $user = User::factory()->create();
        $cart = Cart::create([Cart::USER_ID => $user->id]);
        $this->service()->lockSupplier($cart, User::factory()->create()->id);

        $this->actingAs($user);
        $resolved = app(CartResolver::class)->resolve(request());

        $this->assertSame($cart->id, $resolved->id);
        $this->assertNull($resolved->fresh()->{Cart::SUPPLIER_USER_ID});
        // هر تأمین‌کننده‌ای دوباره مجاز است — خریدِ تازه بسته نمی‌ماند.
        $this->service()->assertSupplierAllowed($resolved->fresh(), User::factory()->create()->id);
    }

    /**
     * قرینه‌ی همان یافته در لحظه‌ی ورود: `resolve()` اول سبدِ مهمان را ادغام می‌کرد و
     * بعد قفلِ خالی را می‌سنجید — پس اقلامِ مهمان (تأمین‌کننده‌ی Y) زیرِ قفلِ کهنه‌ی X
     * می‌نشستند، `releaseIfEmpty` سبد را پر می‌دید و قفلِ X با اقلامِ Y می‌ماند.
     */
    #[Test]
    public function the_stale_lock_goes_before_the_guest_cart_merges_and_the_merged_items_lock_their_own_supplier(): void
    {
        $user = User::factory()->create();
        $cart = Cart::create([Cart::USER_ID => $user->id]);
        $this->service()->lockSupplier($cart, User::factory()->create()->id);

        $supplierY = User::factory()->create();
        $factory = $this->makeFactory();
        $factory->forceFill(['supplier_user_id' => $supplierY->id])->save();
        $location = $this->makeLocation($factory);
        $variant = $this->makeVariant();
        $shipping = ShippingMethod::create([
            ShippingMethod::NAME => 'تست حمل',
            ShippingMethod::CODE => 'SHIP_'.Str::random(6),
        ]);

        $guest = Cart::create([Cart::SESSION_ID => 'guest-'.Str::random(8), Cart::USER_ID => null]);
        $guest->items()->create([
            CartItem::PRODUCT_VARIANT_ID => $variant->id,
            CartItem::FACTORY_ID => $factory->id,
            CartItem::FACTORY_LOCATION_ID => $location->id,
            CartItem::SHIPPING_METHOD_ID => $shipping->id,
            CartItem::QUANTITIES => ['1' => 10],
            CartItem::WEIGHT => 10,
            CartItem::QUANTITY => 10,
            CartItem::UNIT_NAME => 'کیلوگرم',
            CartItem::PRICE => 900_000,
            CartItem::FINAL_PRICE => 900_000,
            CartItem::VAT_AMOUNT => 90_000,
            CartItem::TOTAL_WITH_VAT => 9_900_000,
        ]);

        $this->actingAs($user);
        $request = request();
        $request->cookies->set('cart_session_id', $guest->{Cart::SESSION_ID});

        $resolved = app(CartResolver::class)->resolve($request);

        $this->assertSame($cart->id, $resolved->id);
        $this->assertSame(1, $resolved->items()->count());
        // نه قفلِ کهنه‌ی X، نه بی‌قفل: قفلِ همان اقلامی که حالا در سبد است.
        $this->assertSame($supplierY->id, (int) $resolved->fresh()->{Cart::SUPPLIER_USER_ID});
        $this->assertNull(Cart::find($guest->id));
    }

    #[Test]
    public function an_unlocked_cart_accepts_any_supplier(): void
    {
        $cart = Cart::create([Cart::USER_ID => User::factory()->create()->id]);

        $this->service()->assertSupplierAllowed($cart, User::factory()->create()->id);

        $this->assertNull($cart->fresh()->{Cart::SUPPLIER_USER_ID});
    }

    #[Test]
    public function a_factory_without_a_supplier_does_not_lock_the_cart(): void
    {
        // کارخانه‌ی بدون `supplier_user_id` داده‌ی ناقص است؛ `PlaceOrderAction`
        // جداگانه جلویش را می‌گیرد. اینجا نباید سبد را به `null` قفل کند و
        // بعد هر تأمین‌کننده‌ی واقعی را رد کند.
        $cart = Cart::create([Cart::USER_ID => User::factory()->create()->id]);

        $this->service()->lockSupplier($cart, null);
        $this->assertNull($cart->fresh()->{Cart::SUPPLIER_USER_ID});

        $this->service()->assertSupplierAllowed($cart->fresh(), User::factory()->create()->id);
    }
}
