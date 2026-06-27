<?php

namespace Tests\Feature\Policies;

use App\Models\User;
use App\Models\UserBankAccount;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laratrust\Models\Role;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Tests for UserBankAccountPolicy.
 *
 * Bank-account authorization is the most sensitive in the app — it controls
 * the ability to receive withdrawn funds. The policy enforces strict per-user
 * ownership; only the account's owner (or admin) may touch it.
 *
 * DatabaseTransactions: every test wraps its DB writes in a transaction
 * that is rolled back at teardown. The live local database is NOT mutated.
 */
class UserBankAccountPolicyTest extends TestCase
{
    use DatabaseTransactions;

    private User $owner;

    private User $stranger;

    private User $admin;

    private UserBankAccount $account;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create();
        $this->stranger = User::factory()->create();
        $this->admin = User::factory()->create();

        $adminRole = Role::firstOrCreate(['name' => 'admin'], ['display_name' => 'Admin']);
        $this->admin->addRole($adminRole);

        $this->account = UserBankAccount::create([
            UserBankAccount::USER_ID => $this->owner->id,
            UserBankAccount::BANK_NAME => 'بانک ملت',
            UserBankAccount::ACCOUNT_HOLDER_NAME => 'محمد محمدی',
            UserBankAccount::SHABA => 'IR012345678901234567890123',
            UserBankAccount::STATUS => UserBankAccount::STATUS_APPROVED,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // before() — admin bypass
    // ─────────────────────────────────────────────────────────────────────────

    #[Test]
    public function admin_can_view_any_bank_account(): void
    {
        $this->assertTrue($this->admin->can('view', $this->account));
    }

    #[Test]
    public function admin_can_update_any_bank_account(): void
    {
        $this->assertTrue($this->admin->can('update', $this->account));
    }

    #[Test]
    public function admin_can_delete_any_bank_account(): void
    {
        $this->assertTrue($this->admin->can('delete', $this->account));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Owner permissions
    // ─────────────────────────────────────────────────────────────────────────

    #[Test]
    public function owner_can_view_their_account(): void
    {
        $this->assertTrue($this->owner->can('view', $this->account));
    }

    #[Test]
    public function owner_can_update_their_account(): void
    {
        $this->assertTrue($this->owner->can('update', $this->account));
    }

    #[Test]
    public function owner_can_delete_their_account(): void
    {
        $this->assertTrue($this->owner->can('delete', $this->account));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Strangers — strictly denied
    // ─────────────────────────────────────────────────────────────────────────

    #[Test]
    public function stranger_cannot_view_anothers_bank_account(): void
    {
        $this->assertFalse($this->stranger->can('view', $this->account));
    }

    #[Test]
    public function stranger_cannot_update_anothers_bank_account(): void
    {
        $this->assertFalse($this->stranger->can('update', $this->account));
    }

    #[Test]
    public function stranger_cannot_delete_anothers_bank_account(): void
    {
        $this->assertFalse($this->stranger->can('delete', $this->account));
    }

    #[Test]
    public function multiple_strangers_cannot_access_account(): void
    {
        $alice = User::factory()->create();
        $bob = User::factory()->create();
        $carol = User::factory()->create();

        foreach ([$alice, $bob, $carol] as $user) {
            $this->assertFalse($user->can('view', $this->account));
            $this->assertFalse($user->can('update', $this->account));
            $this->assertFalse($user->can('delete', $this->account));
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Isolation between accounts
    // ─────────────────────────────────────────────────────────────────────────

    #[Test]
    public function user_a_cannot_see_user_b_account(): void
    {
        $userB = User::factory()->create();
        $accountB = UserBankAccount::create([
            UserBankAccount::USER_ID => $userB->id,
            UserBankAccount::BANK_NAME => 'بانک ملی',
            UserBankAccount::ACCOUNT_HOLDER_NAME => 'علی علوی',
            UserBankAccount::SHABA => 'IR099999999999999999999999',
            UserBankAccount::STATUS => UserBankAccount::STATUS_APPROVED,
        ]);

        // Each owner can only see their own account
        $this->assertTrue($this->owner->can('view', $this->account));
        $this->assertFalse($this->owner->can('view', $accountB));

        $this->assertTrue($userB->can('view', $accountB));
        $this->assertFalse($userB->can('view', $this->account));
    }
}
