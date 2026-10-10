<?php

declare(strict_types=1);

namespace Tests\Feature\Financial;

use App\Models\User;
use App\Models\UserBankAccount;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laratrust\Models\Role;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\GrantsAdminPermissions;
use Tests\TestCase;

/**
 * «حساب پیش‌فرض» در طرحِ جدید — تا این‌جا فقط هنگامِ ثبت قابل‌انتخاب بود.
 * فقط مالک، و بعد از هر انتخاب دقیقاً یک حساب پیش‌فرض است.
 */
class BankAccountDefaultTest extends TestCase
{
    use DatabaseTransactions, GrantsAdminPermissions;

    private int $seq = 0;

    private function account(User $owner, bool $default = false): UserBankAccount
    {
        return UserBankAccount::create([
            UserBankAccount::USER_ID => $owner->id,
            UserBankAccount::BANK_NAME => 'ملت',
            UserBankAccount::ACCOUNT_HOLDER_NAME => 'مالک',
            UserBankAccount::SHABA => 'IR'.str_pad((string) ++$this->seq, 24, '7', STR_PAD_LEFT),
            UserBankAccount::STATUS => UserBankAccount::STATUS_APPROVED,
            UserBankAccount::IS_DEFAULT => $default,
        ]);
    }

    #[Test]
    public function the_owner_moves_the_default_and_exactly_one_remains(): void
    {
        $owner = User::factory()->create();
        $first = $this->account($owner, default: true);
        $second = $this->account($owner);
        $othersDefault = $this->account(User::factory()->create(), default: true);

        Sanctum::actingAs($owner);
        $this->postJson("/api/profile/financial/accounts/{$second->id}/default")
            ->assertOk()
            ->assertJsonPath('data.id', $second->id)
            ->assertJsonPath('data.is_default', true);

        $this->assertSame(
            [$second->id],
            UserBankAccount::where(UserBankAccount::USER_ID, $owner->id)->where(UserBankAccount::IS_DEFAULT, true)->pluck('id')->all()
        );
        $this->assertFalse($first->refresh()->is_default);
        $this->assertTrue($othersDefault->refresh()->is_default, 'another user\'s default must not move');
    }

    #[Test]
    public function nobody_else_can_set_it_not_even_an_admin(): void
    {
        $owner = User::factory()->create();
        $account = $this->account($owner);

        Sanctum::actingAs(User::factory()->create());
        $this->postJson("/api/profile/financial/accounts/{$account->id}/default")->assertForbidden();

        $admin = User::factory()->create();
        $admin->addRole(Role::firstOrCreate(['name' => 'admin'], ['display_name' => 'Admin']));
        $this->grantAllAdminPermissions($admin);
        Sanctum::actingAs($admin);
        $this->postJson("/api/profile/financial/accounts/{$account->id}/default")->assertForbidden();

        $this->assertFalse($account->refresh()->is_default);
    }
}
