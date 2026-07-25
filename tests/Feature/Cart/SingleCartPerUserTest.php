<?php

namespace Tests\Feature\Cart;

use App\Models\Cart;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * یک کاربر = یک سبد.
 *
 * پیش از این، `carts.user_id` ایندکس یکتا نداشت و دو مسیرِ خواننده دو ترتیبِ
 * متفاوت داشتند (`firstOrCreate` = کوچک‌ترین id در برابر `latest('updated_at')`)،
 * پس کاربر می‌توانست سبد A را ببیند و سفارشش از سبد B ثبت شود.
 */
class SingleCartPerUserTest extends TestCase
{
    use DatabaseTransactions;

    #[Test]
    public function a_second_cart_row_for_the_same_user_is_rejected_by_the_database(): void
    {
        $user = User::factory()->create();

        Cart::create([Cart::USER_ID => $user->id]);

        $this->expectException(UniqueConstraintViolationException::class);

        Cart::create([Cart::USER_ID => $user->id]);
    }

    #[Test]
    public function guest_carts_are_not_affected_by_the_unique_index(): void
    {
        $first = Cart::create([Cart::SESSION_ID => 'session-a', Cart::USER_ID => null]);
        $second = Cart::create([Cart::SESSION_ID => 'session-b', Cart::USER_ID => null]);

        // ایندکس یکتا روی ستون nullable چند NULL را می‌پذیرد — سبدهای مهمان
        // با session_id از هم جدا می‌شوند، نه با user_id.
        $this->assertNotSame($first->id, $second->id);
        $this->assertDatabaseHas('carts', [Cart::ID => $first->id, Cart::SESSION_ID => 'session-a']);
        $this->assertDatabaseHas('carts', [Cart::ID => $second->id, Cart::SESSION_ID => 'session-b']);
    }

    #[Test]
    public function resolve_for_user_is_idempotent(): void
    {
        $user = User::factory()->create();

        $first = Cart::resolveForUser($user->id);
        $second = Cart::resolveForUser($user->id);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, Cart::where(Cart::USER_ID, $user->id)->count());
    }

    #[Test]
    public function every_reader_selects_the_same_cart_row(): void
    {
        $user = User::factory()->create();
        $cart = Cart::resolveForUser($user->id);

        $this->assertSame($cart->id, Cart::queryForUser($user->id)->first()->id);
        $this->assertSame($cart->id, $user->fresh()->cart->id);
    }
}
