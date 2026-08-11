<?php

namespace Tests\Feature\Cart;

use App\Models\AbandonedCart;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\OrderUnit;
use App\Models\Setting;
use App\Models\ShippingMethod;
use App\Models\User;
use App\Services\Cart\CartParkService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Pricing\CreatesPricingFixtures;
use Tests\TestCase;

/**
 * مهلتِ نگهداری سبد — بند ۳.۱ سند بازطراحی.
 *
 * سبد جای رکوردِ `Order` با وضعیت `pending` را گرفته. آن رکورد موجودی را کسر
 * می‌کرد و کرونِ انقضا جمعش می‌کرد؛ سبد موجودی کسر نمی‌کند، پس تنها چیزی که
 * جلوی «سبدِ ابدی» را می‌گیرد همین مهلت است — و تنها چیزی که جلوی گم‌شدنِ
 * سیگنالِ فروشِ ازدست‌رفته را می‌گیرد، اسنپ‌شاتِ پیش از حذف.
 */
class CartParkExpiryTest extends TestCase
{
    use CreatesPricingFixtures;
    use DatabaseTransactions;

    private function service(): CartParkService
    {
        Cache::forget(Setting::CACHE_KEY);

        return app(CartParkService::class);
    }

    private function setPark(int $minutes): void
    {
        Setting::updateOrCreate(
            [Setting::KEY => 'park_time_minutes'],
            [Setting::VALUE => (string) $minutes]
        );
        Cache::forget(Setting::CACHE_KEY);
    }

    /** سبدِ کاربر با یک ردیفِ واقعی — `cart_items` کلید خارجی دارد، پس ردیف‌های واقعی لازم است. */
    private function cartWithItem(User $user): Cart
    {
        $factory = $this->makeFactory();
        $location = $this->makeLocation($factory);
        $variant = $this->makeVariant();

        $unit = OrderUnit::create([
            OrderUnit::NAME => 'کیلوگرم',
            OrderUnit::CODE => 'KG_'.Str::random(6),
            OrderUnit::UNIT => 'kg',
            OrderUnit::AMOUNT => 1,
        ]);

        $shipping = ShippingMethod::create([
            ShippingMethod::NAME => 'تست حمل',
            ShippingMethod::CODE => 'SHIP_'.Str::random(6),
        ]);

        $cart = Cart::create([Cart::USER_ID => $user->id]);

        $cart->items()->create([
            CartItem::PRODUCT_VARIANT_ID => $variant->id,
            CartItem::FACTORY_ID => $factory->id,
            CartItem::FACTORY_LOCATION_ID => $location->id,
            CartItem::SHIPPING_METHOD_ID => $shipping->id,
            CartItem::QUANTITIES => [(string) $unit->id => 2],
            CartItem::WEIGHT => 1000,
            CartItem::QUANTITY => 2,
            CartItem::UNIT_NAME => 'کیلوگرم',
            CartItem::PRICE => 500,
            CartItem::FINAL_PRICE => 500,
            CartItem::VAT_AMOUNT => 50,
            CartItem::TOTAL_WITH_VAT => 550,
        ]);

        return $cart->fresh();
    }

    #[Test]
    public function the_deadline_is_set_when_the_first_item_lands_in_an_empty_cart(): void
    {
        $this->setPark(120);
        $cart = Cart::create([Cart::USER_ID => User::factory()->create()->id]);

        $this->assertNull($cart->{Cart::PARKED_UNTIL});

        $this->service()->parkIfNeeded($cart);

        $parked = $cart->fresh()->{Cart::PARKED_UNTIL};
        $this->assertNotNull($parked);
        $this->assertEqualsWithDelta(120, now()->diffInMinutes($parked), 1);
    }

    #[Test]
    public function editing_the_cart_does_not_push_the_deadline_forward(): void
    {
        // این خودِ قاعده است، نه بهینه‌سازی: با ریست‌شدن، هر افزودن/کم‌کردنِ یک
        // قلم مهلت را جلو می‌برد و سبد عملاً هرگز منقضی نمی‌شود.
        $this->setPark(120);
        $cart = Cart::create([Cart::USER_ID => User::factory()->create()->id]);

        $this->service()->parkIfNeeded($cart);
        $first = $cart->fresh()->{Cart::PARKED_UNTIL};

        $this->travel(30)->minutes();
        $this->service()->parkIfNeeded($cart->fresh());

        $this->assertEquals(
            $first->toIso8601String(),
            $cart->fresh()->{Cart::PARKED_UNTIL}->toIso8601String(),
            'ویرایش سبد نباید مهلت را ریست کند.',
        );
    }

    #[Test]
    public function a_guest_cart_never_gets_a_deadline(): void
    {
        // سبدِ مهمان مسیرِ نگهداشتِ خودش را دارد (`carts:prune-guests`).
        $cart = Cart::create([Cart::SESSION_ID => 'guest-session-1']);

        $this->service()->parkIfNeeded($cart);

        $this->assertNull($cart->fresh()->{Cart::PARKED_UNTIL});
    }

    #[Test]
    public function emptying_the_cart_clears_the_deadline_and_the_supplier_lock(): void
    {
        $this->setPark(120);
        $user = User::factory()->create();
        $cart = $this->cartWithItem($user);

        $this->service()->parkIfNeeded($cart);
        $cart->forceFill([Cart::SUPPLIER_USER_ID => User::factory()->create()->id])->save();

        // با آیتم موجود، آزاد نمی‌شود.
        $this->service()->releaseIfEmpty($cart->fresh());
        $this->assertNotNull($cart->fresh()->{Cart::PARKED_UNTIL});

        $cart->items()->delete();
        $this->service()->releaseIfEmpty($cart->fresh());

        $fresh = $cart->fresh();
        $this->assertNull($fresh->{Cart::PARKED_UNTIL});
        $this->assertNull($fresh->{Cart::SUPPLIER_USER_ID});
    }

    #[Test]
    public function the_snapshot_is_written_before_the_cart_is_deleted(): void
    {
        $this->setPark(120);
        $user = User::factory()->create();
        $cart = $this->cartWithItem($user);
        $cart->forceFill([Cart::PARKED_UNTIL => now()->subMinute()])->save();
        $cart->update([Cart::COUPON_CODE => 'OFF10']);

        $this->artisan('carts:expire-parked')->assertSuccessful();

        $this->assertNull(Cart::find($cart->id), 'سبد منقضی باید حذف شده باشد.');

        $snapshot = AbandonedCart::where(AbandonedCart::USER_ID, $user->id)->first();
        $this->assertNotNull($snapshot, 'اسنپ‌شات باید پیش از حذف نوشته شده باشد.');
        $this->assertSame('OFF10', $snapshot->{AbandonedCart::COUPON_CODE});
        $this->assertCount(1, $snapshot->{AbandonedCart::ITEMS});
        $this->assertEqualsWithDelta(550, (float) $snapshot->{AbandonedCart::TOTAL_AMOUNT}, 0.01);
    }

    #[Test]
    public function a_cart_whose_deadline_has_not_passed_is_left_alone(): void
    {
        $this->setPark(120);
        $user = User::factory()->create();
        $cart = $this->cartWithItem($user);
        $this->service()->parkIfNeeded($cart);

        $this->artisan('carts:expire-parked')->assertSuccessful();

        $this->assertNotNull(Cart::find($cart->id));
        $this->assertSame(0, AbandonedCart::where(AbandonedCart::USER_ID, $user->id)->count());
    }

    #[Test]
    public function no_warning_is_sent_when_the_park_window_is_shorter_than_the_lead(): void
    {
        // بند ۳.۱: اگر `park_time_minutes < 30` هشداری ارسال نشود — پیامکی که
        // همزمان با ساختِ سبد می‌رسد فقط هزینه است.
        $this->setPark(20);
        $cart = Cart::create([Cart::USER_ID => User::factory()->create()->id]);
        $this->service()->parkIfNeeded($cart);

        $this->assertFalse($this->service()->shouldWarn($cart->fresh()));
    }

    #[Test]
    public function a_warning_is_due_inside_the_lead_window_and_only_once(): void
    {
        $this->setPark(120);
        $cart = Cart::create([Cart::USER_ID => User::factory()->create()->id]);
        $this->service()->parkIfNeeded($cart);

        $this->assertFalse($this->service()->shouldWarn($cart->fresh()), 'خارج از پنجره نباید هشدار بدهد.');

        $this->travel(95)->minutes();
        $this->assertTrue($this->service()->shouldWarn($cart->fresh()), 'داخل پنجره باید هشدار بدهد.');

        // پس از علامت‌گذاری، دیگر نه — کرون هر ۵ دقیقه اجرا می‌شود.
        $cart->forceFill([Cart::PARK_WARNED_AT => now()])->save();
        $this->assertFalse($this->service()->shouldWarn($cart->fresh()));
    }
}
