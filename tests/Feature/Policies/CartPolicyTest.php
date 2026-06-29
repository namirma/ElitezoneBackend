<?php

namespace Tests\Feature\Policies;

use App\Models\Cart;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laratrust\Models\Role;
use Tests\TestCase;

/**
 * Tests for CartPolicy.
 *
 * Each user has a single cart bound to USER_ID. Strangers must never see
 * or mutate someone else's cart; admins bypass via OrderPolicy-style
 * before() hook.
 */
class CartPolicyTest extends TestCase
{
    use DatabaseTransactions;

    private User $owner;
    private User $stranger;
    private User $admin;
    private Cart $cart;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner    = User::factory()->create();
        $this->stranger = User::factory()->create();
        $this->admin    = User::factory()->create();

        $adminRole = Role::firstOrCreate(['name' => 'admin'], ['display_name' => 'Admin']);
        $this->admin->addRole($adminRole);

        $this->cart = Cart::create([
            Cart::USER_ID => $this->owner->id,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // before() — admin bypass
    // ─────────────────────────────────────────────────────────────────────────

    /** @test */
    public function admin_can_view_any_cart(): void
    {
        $this->assertTrue($this->admin->can('view', $this->cart));
    }

    /** @test */
    public function admin_can_update_any_cart(): void
    {
        $this->assertTrue($this->admin->can('update', $this->cart));
    }

    /** @test */
    public function admin_can_delete_any_cart(): void
    {
        $this->assertTrue($this->admin->can('delete', $this->cart));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Owner permissions
    // ─────────────────────────────────────────────────────────────────────────

    /** @test */
    public function owner_can_view_their_cart(): void
    {
        $this->assertTrue($this->owner->can('view', $this->cart));
    }

    /** @test */
    public function owner_can_update_their_cart(): void
    {
        $this->assertTrue($this->owner->can('update', $this->cart));
    }

    /** @test */
    public function owner_can_delete_their_cart(): void
    {
        $this->assertTrue($this->owner->can('delete', $this->cart));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Strangers blocked
    // ─────────────────────────────────────────────────────────────────────────

    /** @test */
    public function stranger_cannot_view_anothers_cart(): void
    {
        $this->assertFalse($this->stranger->can('view', $this->cart));
    }

    /** @test */
    public function stranger_cannot_update_anothers_cart(): void
    {
        $this->assertFalse($this->stranger->can('update', $this->cart));
    }

    /** @test */
    public function stranger_cannot_delete_anothers_cart(): void
    {
        $this->assertFalse($this->stranger->can('delete', $this->cart));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Cross-user isolation
    // ─────────────────────────────────────────────────────────────────────────

    /** @test */
    public function carts_are_isolated_between_users(): void
    {
        $userB = User::factory()->create();
        $cartB = Cart::create([Cart::USER_ID => $userB->id]);

        $this->assertTrue($this->owner->can('view', $this->cart));
        $this->assertFalse($this->owner->can('view', $cartB));

        $this->assertTrue($userB->can('view', $cartB));
        $this->assertFalse($userB->can('view', $this->cart));
    }
}
