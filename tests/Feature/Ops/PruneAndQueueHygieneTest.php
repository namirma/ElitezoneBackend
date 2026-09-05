<?php

namespace Tests\Feature\Ops;

use App\Models\Cart;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Laratrust\Models\Role;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\GrantsAdminPermissions;
use Tests\TestCase;

/**
 * دو کارِ نگهداشتی که «سقف داشتند ولی حلقه نداشتند» یا هرگز خالی نمی‌شدند.
 */
class PruneAndQueueHygieneTest extends TestCase
{
    use DatabaseTransactions;
    use GrantsAdminPermissions;

    // ─────────────── carts:prune-guests ───────────────

    #[Test]
    public function pruning_clears_the_whole_backlog_not_just_one_chunk(): void
    {
        // chunk کوچک تا با چند ردیف، چندین دور لازم شود.
        config(['cart.prune_chunk' => 2, 'cart.guest_retention_days' => 30]);

        $old = now()->subDays(40);
        for ($i = 0; $i < 7; $i++) {
            $cart = Cart::create([Cart::USER_ID => null, Cart::SESSION_ID => 'sess-'.Str::random(12)]);
            // updated_at باید صریح عقب برود؛ Eloquent هنگام create آن را now() می‌گذارد.
            Cart::where(Cart::ID, $cart->id)->update(['updated_at' => $old, 'created_at' => $old]);
        }

        $before = Cart::whereNull(Cart::USER_ID)->where('updated_at', '<', now()->subDays(30))->count();
        $this->assertGreaterThanOrEqual(7, $before);

        $this->artisan('carts:prune-guests')->assertExitCode(0);

        $this->assertSame(
            0,
            Cart::whereNull(Cart::USER_ID)->where('updated_at', '<', now()->subDays(30))->count(),
            'یک اجرا باید کلِ عقب‌ماندگی را پاک کند، نه فقط یک chunk — وگرنه کرونِ روزانه هرگز به آن نمی‌رسد.'
        );
    }

    #[Test]
    public function a_logged_in_users_cart_is_never_pruned(): void
    {
        config(['cart.prune_chunk' => 50, 'cart.guest_retention_days' => 30]);

        $user = User::factory()->create();
        $cart = Cart::create([Cart::USER_ID => $user->id]);
        Cart::where(Cart::ID, $cart->id)->update(['updated_at' => now()->subDays(400)]);

        $this->artisan('carts:prune-guests')->assertExitCode(0);

        $this->assertNotNull(Cart::find($cart->id), 'سبدِ کاربرِ لاگین‌شده هرگز نباید حذف شود.');
    }

    // ─────────────── صف مودیانِ ادمین ───────────────

    private function admin(): User
    {
        $admin = User::factory()->create();
        $admin->addRole(Role::firstOrCreate(['name' => 'admin'], ['display_name' => 'Admin']));
        $this->grantAllAdminPermissions($admin);

        return $admin;
    }

    private function weighedOrder(?int $eliteYarId, array $refs = []): Order
    {
        $order = Order::create([
            Order::PLACER_USER_ID => User::factory()->create()->id,
            Order::ELITE_YAR_USER_ID => $eliteYarId,
            Order::ORDER_NUMBER => 'MOD-'.Str::random(10),
            Order::STATUS => Order::STATUS_DELIVERED,
        ]);

        $order->forceFill(array_merge([
            Order::WEIGHING_COMPLETED_AT => now(),
        ], $refs))->save();

        OrderItem::factory()->create([OrderItem::ORDER_ID => $order->id]);

        return $order;
    }

    #[Test]
    public function an_order_with_no_elite_yar_leaves_the_queue_once_its_own_docs_are_filed(): void
    {
        // سند Q را فقط `order.elite_yar_user_id` می‌تواند ثبت کند. سفارشِ بدونِ
        // الیت‌یار هرگز آن را نمی‌گیرد، پس با شرطِ قبلی تا ابد در صف می‌ماند.
        $done = $this->weighedOrder(null, [
            Order::SUPPLIER_MOADIAN_REF => 'S-1',
            Order::SYSTEM_MOADIAN_REF => 'P-1',
        ]);

        Sanctum::actingAs($this->admin());
        $ids = collect($this->getJson('/api/admin/financial/pending-moadian?per_page=200')->json('data'))->pluck('id');

        $this->assertNotContains(
            $done->id,
            $ids->all(),
            'سفارشِ بدونِ الیت‌یار که هر دو سندِ خودش را دارد نباید در صف بماند.'
        );
    }

    #[Test]
    public function an_order_that_really_is_missing_a_document_stays_in_the_queue(): void
    {
        $eliteYar = User::factory()->create();

        // الیت‌یار دارد ولی سند Q ثبت نشده → واقعاً معلق است.
        $pending = $this->weighedOrder($eliteYar->id, [
            Order::SUPPLIER_MOADIAN_REF => 'S-2',
            Order::SYSTEM_MOADIAN_REF => 'P-2',
        ]);

        Sanctum::actingAs($this->admin());
        $ids = collect($this->getJson('/api/admin/financial/pending-moadian?per_page=200')->json('data'))->pluck('id');

        $this->assertContains($pending->id, $ids->all(), 'سند Q واقعاً جا مانده — باید در صف بماند.');
    }
}
