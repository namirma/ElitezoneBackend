<?php

namespace Tests\Feature\Cart;

use App\Models\Cart;
use App\Models\User;
use App\Services\Cart\CartPriceValidityResolver;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * `price_valid_until` در پاسخِ سبد — مهلتِ اعتبارِ قیمت/موجودی.
 *
 * قرارداد: **کلید همیشه هست**؛ `null` یعنی مهلتی اعلام نشده، نه «منقضی». فرانت
 * باید بتواند بدونِ چک‌کردنِ وجودِ کلید مستقیم بخواندش، وگرنه نبودِ کلید و مقدارِ
 * null به دو رفتار متفاوت منجر می‌شود.
 *
 * منبعِ نهاییِ مقدار، API تأمین‌کننده است؛ تا آن زمان از `cart.price_validity_ttl_minutes`.
 */
class CartPriceValidityTest extends TestCase
{
    use DatabaseTransactions;

    #[Test]
    public function the_cart_response_always_carries_the_key_and_defaults_to_null(): void
    {
        config()->set('cart.price_validity_ttl_minutes', null);

        Sanctum::actingAs(User::factory()->create());

        $response = $this->getJson('/api/cart')->assertOk();

        $response->assertJsonStructure(['data' => ['price_valid_until']]);
        $this->assertNull($response->json('data.price_valid_until'),
            'بدون مهلتِ پیکربندی‌شده، مقدار باید null باشد نه یک تاریخِ ساختگی');
    }

    #[Test]
    public function a_configured_ttl_produces_a_deadline_measured_from_the_carts_last_change(): void
    {
        config()->set('cart.price_validity_ttl_minutes', 120);

        $user = User::factory()->create();
        $cart = Cart::create([
            Cart::USER_ID => $user->id,
            Cart::SESSION_ID => (string) Str::uuid(),
        ]);

        $expected = $cart->updated_at->copy()->addMinutes(120);

        $resolved = app(CartPriceValidityResolver::class)->resolve($cart);

        $this->assertNotNull($resolved);
        $this->assertSame($expected->toIso8601String(), $resolved->toIso8601String());
    }

    #[Test]
    public function the_deadline_is_not_pushed_forward_by_merely_reading_the_cart(): void
    {
        // مبنا باید `updated_at` باشد نه now() — وگرنه هر refresh مهلت را جلو
        // می‌برد و اعتبار عملاً هرگز تمام نمی‌شود.
        config()->set('cart.price_validity_ttl_minutes', 60);

        $user = User::factory()->create();
        $cart = Cart::create([
            Cart::USER_ID => $user->id,
            Cart::SESSION_ID => (string) Str::uuid(),
        ]);

        $resolver = app(CartPriceValidityResolver::class);
        $first = $resolver->resolve($cart);

        $this->travel(10)->minutes();
        $second = $resolver->resolve($cart->fresh());

        $this->assertSame($first->toIso8601String(), $second->toIso8601String());
    }
}
