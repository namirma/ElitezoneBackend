<?php

namespace Tests\Feature\Policies;

use App\Models\Address;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laratrust\Models\Role;
use Tests\TestCase;

/**
 * Tests for AddressPolicy — owner-only access; admin bypass.
 */
class AddressPolicyTest extends TestCase
{
    use DatabaseTransactions;

    private User $owner;
    private User $stranger;
    private User $admin;
    private Address $address;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner    = User::factory()->create();
        $this->stranger = User::factory()->create();
        $this->admin    = User::factory()->create();

        $adminRole = Role::firstOrCreate(['name' => 'admin'], ['display_name' => 'Admin']);
        $this->admin->addRole($adminRole);

        $this->address = Address::create([
            Address::USER_ID         => $this->owner->id,
            Address::POSTAL_ADDRESS  => 'تهران، خیابان آزادی، پلاک ۱۲',
            Address::RECEIVER_NAME   => 'حسن حسینی',
            Address::RECEIVER_MOBILE => '09121234567',
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Admin bypass
    // ─────────────────────────────────────────────────────────────────────────

    /** @test */
    public function admin_can_view_any_address(): void
    {
        $this->assertTrue($this->admin->can('view', $this->address));
    }

    /** @test */
    public function admin_can_update_any_address(): void
    {
        $this->assertTrue($this->admin->can('update', $this->address));
    }

    /** @test */
    public function admin_can_delete_any_address(): void
    {
        $this->assertTrue($this->admin->can('delete', $this->address));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Owner
    // ─────────────────────────────────────────────────────────────────────────

    /** @test */
    public function owner_can_view_their_address(): void
    {
        $this->assertTrue($this->owner->can('view', $this->address));
    }

    /** @test */
    public function owner_can_update_their_address(): void
    {
        $this->assertTrue($this->owner->can('update', $this->address));
    }

    /** @test */
    public function owner_can_delete_their_address(): void
    {
        $this->assertTrue($this->owner->can('delete', $this->address));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Strangers blocked
    // ─────────────────────────────────────────────────────────────────────────

    /** @test */
    public function stranger_cannot_view_anothers_address(): void
    {
        $this->assertFalse($this->stranger->can('view', $this->address));
    }

    /** @test */
    public function stranger_cannot_update_anothers_address(): void
    {
        $this->assertFalse($this->stranger->can('update', $this->address));
    }

    /** @test */
    public function stranger_cannot_delete_anothers_address(): void
    {
        $this->assertFalse($this->stranger->can('delete', $this->address));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Cross-user isolation
    // ─────────────────────────────────────────────────────────────────────────

    /** @test */
    public function addresses_are_isolated_between_users(): void
    {
        $userB = User::factory()->create();
        $addressB = Address::create([
            Address::USER_ID         => $userB->id,
            Address::POSTAL_ADDRESS  => 'اصفهان، چهارباغ',
            Address::RECEIVER_NAME   => 'مریم احمدی',
            Address::RECEIVER_MOBILE => '09357654321',
        ]);

        $this->assertTrue($this->owner->can('view', $this->address));
        $this->assertFalse($this->owner->can('view', $addressB));

        $this->assertTrue($userB->can('view', $addressB));
        $this->assertFalse($userB->can('view', $this->address));
    }
}
