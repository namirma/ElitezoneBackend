<?php

declare(strict_types=1);

namespace Tests\Feature\Cart;

use App\Jobs\Notification\SendSmsJob;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\OrderUnit;
use App\Models\Setting;
use App\Models\ShippingMethod;
use App\Models\SupplierProfile;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laratrust\Models\Role;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Pricing\CreatesPricingFixtures;
use Tests\TestCase;

/**
 * «اول لغوِ لینک، بعد قفلِ سبد» — تصمیم مدیریت (گزینه‌ی ب، ۲۰۲۶-۰۹-۱۴).
 *
 * ممیزیِ ۲۰۲۶-۰۹-۱۲ (بند ۴) نشان داد الیت‌یار پس از ارسالِ لینک هنوز می‌توانست
 * سبد را عوض کند، پس مبلغِ پیامک‌شده با مبلغِ لحظه‌ی پرداخت فرق می‌کرد. حالا تا
 * لینک باز است هر تغییرِ سبد ۴۰۹ می‌گیرد و تنها راهِ بازکردن، لغوِ لینک است —
 * که با عنوان «لغو سفارش» به کارفرما پیامک می‌شود (شرطِ علی).
 */
class CartPaymentLinkLockTest extends TestCase
{
    use CreatesPricingFixtures;
    use DatabaseTransactions;

    private User $eliteYar;

    private User $employer;

    private $factory;

    private $location;

    private $variant;

    private ShippingMethod $shipping;

    private OrderUnit $unit;

    protected function setUp(): void
    {
        parent::setUp();

        // `throttle:sms` روی ارسال و لغو یک سطل دارد و کشِ array بینِ تست‌های یک
        // پروسه می‌ماند؛ خودِ throttle در `Security/RateLimitCoverageTest` پین
        // شده، این‌جا موضوع قفل است.
        $this->withoutMiddleware(ThrottleRequests::class);

        Setting::updateOrCreate([Setting::KEY => 'shaparak_value'], [Setting::VALUE => '2000000000']);
        Setting::updateOrCreate([Setting::KEY => 'reserve_value'], [Setting::VALUE => '2000000000']);
        Cache::forget(Setting::CACHE_KEY);

        $this->eliteYar = User::factory()->create();
        $this->eliteYar->addRole(Role::firstOrCreate(['name' => 'engineer'], ['display_name' => 'Engineer']));

        $this->employer = User::factory()->create();
        $this->employer->addRole(Role::firstOrCreate(['name' => 'employer'], ['display_name' => 'Employer']));
        $this->eliteYar->associatedEmployers()->attach($this->employer->id, ['status' => 'approved']);

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

        $this->shipping = ShippingMethod::create([
            ShippingMethod::NAME => 'تست حمل',
            ShippingMethod::CODE => 'SHIP_'.Str::random(6),
        ]);

        $this->unit = OrderUnit::create([
            OrderUnit::NAME => 'کیلوگرم',
            OrderUnit::CODE => 'KG_'.Str::random(6),
            OrderUnit::UNIT => 'kg',
            OrderUnit::AMOUNT => 1,
        ]);
    }

    private function fillCart(): Cart
    {
        $cart = Cart::create([Cart::USER_ID => $this->eliteYar->id]);
        $cart->forceFill([
            Cart::PARKED_UNTIL => now()->addHours(2),
            Cart::SHIPPING_METHOD_ID => $this->shipping->id,
        ])->save();

        $kg = 100;
        $cart->items()->create([
            CartItem::PRODUCT_VARIANT_ID => $this->variant->id,
            CartItem::FACTORY_ID => $this->factory->id,
            CartItem::FACTORY_LOCATION_ID => $this->location->id,
            CartItem::SHIPPING_METHOD_ID => $this->shipping->id,
            CartItem::QUANTITIES => [(string) $this->unit->id => $kg],
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

    /** لینک از مسیرِ واقعی صادر می‌شود، نه با forceFill — تا قفل همان چیزی باشد که کاربر می‌بیند. */
    private function lockedCart(): Cart
    {
        $cart = $this->fillCart();
        Sanctum::actingAs($this->eliteYar);
        $this->postJson('/api/cart/payment-link', ['beneficiary_user_id' => $this->employer->id])->assertOk();

        return $cart->fresh(['items']);
    }

    /** ویژگی‌های job محافظت‌شده‌اند؛ closure با bind به خودِ job می‌خواندشان. */
    private function smsMatches(SendSmsJob $job, string $mobile, string $prefix): bool
    {
        return (fn () => $this->mobile === $mobile && str_starts_with($this->message, $prefix))->call($job);
    }

    // ── قفل ────────────────────────────────────────────────────────────────

    #[Test]
    public function every_cart_mutation_is_refused_with_409_while_the_link_is_outstanding(): void
    {
        Queue::fake();
        $cart = $this->lockedCart();
        $item = $cart->items->first();

        $attempts = [
            ['post', '/api/cart/items', [
                'product_variant_id' => $this->variant->id,
                'factory_id' => $this->factory->id,
                'location_id' => $this->location->id,
                'shipping_method_id' => $this->shipping->id,
                'quantities' => [(string) $this->unit->id => 10],
            ]],
            ['patch', "/api/cart/items/{$item->id}", [
                'quantities' => [(string) $this->unit->id => 5],
                'location_id' => $this->location->id,
            ]],
            ['delete', "/api/cart/items/{$item->id}", []],
            ['patch', '/api/cart/filters', ['filter_categories' => []]],
            ['post', '/api/cart/coupon', ['coupon_code' => null]],
        ];

        foreach ($attempts as [$method, $url, $body]) {
            $response = $this->json($method, $url, $body);
            $response->assertStatus(409);
            $this->assertSame(__('checkout.cart_locked_by_payment_link'), $response->json('message'), "$method $url");
        }

        // هیچ‌کدام اثری نگذاشته‌اند.
        $this->assertCount(1, $cart->fresh()->items);
        $this->assertSame(100.0, (float) $item->fresh()->{CartItem::QUANTITY});
    }

    #[Test]
    public function reading_the_cart_is_still_allowed_while_locked(): void
    {
        Queue::fake();
        $this->lockedCart();

        $this->getJson('/api/cart')->assertOk();
    }

    #[Test]
    public function payment_options_exposes_the_lock(): void
    {
        Queue::fake();
        $this->lockedCart();

        $response = $this->getJson('/api/checkout/payment-options')
            ->assertOk()
            ->assertJsonPath('data.cart_locked', true)
            ->assertJsonPath('data.is_for_beneficiary', true);

        $this->assertNotNull($response->json('data.payment_link_sent_at'));
    }

    #[Test]
    public function an_unlocked_cart_reports_cart_locked_false(): void
    {
        $this->fillCart();
        Sanctum::actingAs($this->eliteYar);

        $this->getJson('/api/checkout/payment-options')
            ->assertOk()
            ->assertJsonPath('data.cart_locked', false)
            ->assertJsonPath('data.payment_link_sent_at', null);
    }

    #[Test]
    public function re_sending_to_the_same_employer_is_allowed_but_a_different_employer_is_a_mutation(): void
    {
        Queue::fake();
        $this->lockedCart();

        $this->postJson('/api/cart/payment-link', ['beneficiary_user_id' => $this->employer->id])->assertOk();

        $other = User::factory()->create();
        $this->eliteYar->associatedEmployers()->attach($other->id, ['status' => 'approved']);

        $this->postJson('/api/cart/payment-link', ['beneficiary_user_id' => $other->id])->assertStatus(409);
    }

    // ── لغو ────────────────────────────────────────────────────────────────

    #[Test]
    public function cancelling_unlocks_the_cart_invalidates_the_link_and_smses_the_employer_with_the_cancel_title(): void
    {
        Queue::fake();
        $cart = $this->lockedCart();
        $item = $cart->items->first();

        $this->postJson('/api/checkout/payment-link/cancel')
            ->assertOk()
            ->assertJsonPath('data.cancelled', true);

        $fresh = $cart->fresh();
        $this->assertNull($fresh->{Cart::BENEFICIARY_USER_ID});
        $this->assertNull($fresh->{Cart::PAYMENT_LINK_SENT_AT});
        $this->assertNotNull($fresh->{Cart::PAYMENT_LINK_CANCELLED_AT});

        // شرطِ علی: پیامک به کارفرما، با عنوان «لغو سفارش».
        Queue::assertPushed(
            SendSmsJob::class,
            fn (SendSmsJob $job) => $this->smsMatches($job, $this->employer->mobile, 'لغو سفارش')
        );

        // سبد باز شد: همان تغییری که قبلاً ۴۰۹ می‌گرفت حالا رد می‌شود.
        $this->deleteJson("/api/cart/items/{$item->id}")->assertOk();

        // لینکِ قدیمیِ کارفرما «لغو سفارش» می‌گوید، نه «لینکی نبود».
        Sanctum::actingAs($this->employer);
        $this->getJson("/api/checkout/pay-for/{$cart->id}")
            ->assertStatus(410)
            ->assertJsonPath('message', __('checkout.payment_link_cancelled'));
        $this->postJson("/api/checkout/pay-for/{$cart->id}")->assertStatus(410);
    }

    #[Test]
    public function cancelling_is_idempotent_and_sends_no_second_sms(): void
    {
        Queue::fake();
        $this->lockedCart();

        $this->postJson('/api/checkout/payment-link/cancel')->assertOk()->assertJsonPath('data.cancelled', true);
        $this->postJson('/api/checkout/payment-link/cancel')->assertOk()->assertJsonPath('data.cancelled', false);

        Queue::assertPushed(SendSmsJob::class, 2); // یکی لینک، یکی لغو — نه سه‌تا
    }

    #[Test]
    public function cancelling_with_no_link_is_a_harmless_no_op(): void
    {
        Queue::fake();
        $this->fillCart();
        Sanctum::actingAs($this->eliteYar);

        $this->postJson('/api/checkout/payment-link/cancel')->assertOk()->assertJsonPath('data.cancelled', false);
        Queue::assertNothingPushed();
    }

    #[Test]
    public function a_fresh_link_after_a_cancel_clears_the_cancelled_state(): void
    {
        Queue::fake();
        $cart = $this->lockedCart();

        $this->postJson('/api/checkout/payment-link/cancel')->assertOk();
        $this->postJson('/api/cart/payment-link', ['beneficiary_user_id' => $this->employer->id])->assertOk();

        $this->assertNull($cart->fresh()->{Cart::PAYMENT_LINK_CANCELLED_AT});

        Sanctum::actingAs($this->employer);
        $this->getJson("/api/checkout/pay-for/{$cart->id}")->assertOk();
    }
}
