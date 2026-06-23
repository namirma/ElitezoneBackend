<?php

namespace Tests\Feature\Policies;

use App\Models\User;
use App\Models\Wishlist;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laratrust\Models\Role;
use Tests\TestCase;

/**
 * Tests for WishlistPolicy — owner-only access; admin bypass.
 */
class WishlistPolicyTest extends TestCase
{
    use DatabaseTransactions;

    private User $owner;

    private User $stranger;

    private User $admin;

    private Wishlist $wishlist;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create();
        $this->stranger = User::factory()->create();
        $this->admin = User::factory()->create();

        $adminRole = Role::firstOrCreate(['name' => 'admin'], ['display_name' => 'Admin']);
        $this->admin->addRole($adminRole);

        $this->wishlist = Wishlist::create([
            Wishlist::USER_ID => $this->owner->id,
            Wishlist::NAME => 'لیست خرید من',
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Admin bypass
    // ─────────────────────────────────────────────────────────────────────────

    /** @test */
    public function admin_can_view_any_wishlist(): void
    {
        $this->assertTrue($this->admin->can('view', $this->wishlist));
    }

    /** @test */
    public function admin_can_update_any_wishlist(): void
    {
        $this->assertTrue($this->admin->can('update', $this->wishlist));
    }

    /** @test */
    public function admin_can_delete_any_wishlist(): void
    {
        $this->assertTrue($this->admin->can('delete', $this->wishlist));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Owner
    // ─────────────────────────────────────────────────────────────────────────

    /** @test */
    public function owner_can_view_their_wishlist(): void
    {
        $this->assertTrue($this->owner->can('view', $this->wishlist));
    }

    /** @test */
    public function owner_can_update_their_wishlist(): void
    {
        $this->assertTrue($this->owner->can('update', $this->wishlist));
    }

    /** @test */
    public function owner_can_delete_their_wishlist(): void
    {
        $this->assertTrue($this->owner->can('delete', $this->wishlist));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Strangers blocked
    // ─────────────────────────────────────────────────────────────────────────

    /** @test */
    public function stranger_cannot_view_anothers_wishlist(): void
    {
        $this->assertFalse($this->stranger->can('view', $this->wishlist));
    }

    /** @test */
    public function stranger_cannot_update_anothers_wishlist(): void
    {
        $this->assertFalse($this->stranger->can('update', $this->wishlist));
    }

    /** @test */
    public function stranger_cannot_delete_anothers_wishlist(): void
    {
        $this->assertFalse($this->stranger->can('delete', $this->wishlist));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Cross-user isolation
    // ─────────────────────────────────────────────────────────────────────────

    /** @test */
    public function wishlists_are_isolated_between_users(): void
    {
        $userB = User::factory()->create();
        $wishlistB = Wishlist::create([
            Wishlist::USER_ID => $userB->id,
            Wishlist::NAME => 'لیست B',
        ]);

        $this->assertTrue($this->owner->can('view', $this->wishlist));
        $this->assertFalse($this->owner->can('view', $wishlistB));

        $this->assertTrue($userB->can('view', $wishlistB));
        $this->assertFalse($userB->can('view', $this->wishlist));
    }
}
