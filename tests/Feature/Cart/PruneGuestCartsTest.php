<?php

namespace Tests\Feature\Cart;

use App\Models\Cart;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * `GET /api/cart` عمومی است و برای هر بازدیدِ بدونِ کوکیِ cart_session_id یک ردیف
 * carts می‌سازد. خزنده‌ها کوکی برنمی‌گردانند، پس جدول بی‌مرز رشد می‌کرد و هیچ
 * سیاست نگهداشتی نداشت. `carts:prune-guests` آن را جمع می‌کند — و هرگز نباید
 * سبدِ یک کاربرِ لاگین‌شده را حذف کند.
 */
class PruneGuestCartsTest extends TestCase
{
    use DatabaseTransactions;

    #[Test]
    public function it_deletes_stale_guest_carts_but_never_a_user_cart(): void
    {
        $user = User::factory()->create();

        $staleGuest = Cart::create([Cart::SESSION_ID => 'stale-'.uniqid(), Cart::USER_ID => null]);
        $staleGuest->forceFill(['updated_at' => now()->subDays(90)])->save();

        $freshGuest = Cart::create([Cart::SESSION_ID => 'fresh-'.uniqid(), Cart::USER_ID => null]);

        $userCart = Cart::create([Cart::USER_ID => $user->id]);
        $userCart->forceFill(['updated_at' => now()->subDays(400)])->save();

        $this->artisan('carts:prune-guests')->assertSuccessful();

        $this->assertNull(Cart::find($staleGuest->id), 'سبد مهمانِ کهنه باید حذف شود.');
        $this->assertNotNull(Cart::find($freshGuest->id), 'سبد مهمانِ تازه نباید حذف شود.');
        $this->assertNotNull(
            Cart::find($userCart->id),
            'سبد کاربرِ لاگین‌شده هرگز نباید حذف شود، هرقدر هم قدیمی باشد.'
        );
    }

    #[Test]
    public function a_guest_cart_is_never_resolved_from_a_logged_in_users_cart(): void
    {
        // cart_session_id یک کوکیِ تحتِ کنترلِ کلاینت است. اگر resolver شرطِ
        // whereNull(user_id) را نداشته باشد، یک مهمان می‌تواند با حدسِ session_id
        // به سبدِ یک کاربر برسد. امروز سبدِ کاربر session_id ندارد — این تست همان
        // فرضِ ضمنی را صریح نگه می‌دارد.
        $user = User::factory()->create();
        $userCart = Cart::create([Cart::USER_ID => $user->id, Cart::SESSION_ID => 'leaky-session']);

        $resolved = $this->withCookie('cart_session_id', 'leaky-session')
            ->getJson('/api/cart');

        $resolved->assertOk();
        $this->assertNotSame(
            $userCart->id,
            data_get($resolved->json(), 'data.cart.id'),
            'سبدِ مهمان هرگز نباید به سبدِ یک کاربرِ لاگین‌شده resolve شود.'
        );
    }
}
