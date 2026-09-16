<?php

namespace Tests\Feature\Cart;

use App\Exceptions\BusinessException;
use App\Models\Cart;
use App\Models\User;
use App\Services\Cart\CartParkService;
use App\Services\Cart\CartResolver;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\Test;
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
        // کارخانه‌ی بدون `supplier_user_id` دادهٔ ناقص است؛ `PlaceOrderAction`
        // جداگانه جلویش را می‌گیرد. اینجا نباید سبد را به `null` قفل کند و
        // بعد هر تأمین‌کننده‌ی واقعی را رد کند.
        $cart = Cart::create([Cart::USER_ID => User::factory()->create()->id]);

        $this->service()->lockSupplier($cart, null);
        $this->assertNull($cart->fresh()->{Cart::SUPPLIER_USER_ID});

        $this->service()->assertSupplierAllowed($cart->fresh(), User::factory()->create()->id);
    }
}
