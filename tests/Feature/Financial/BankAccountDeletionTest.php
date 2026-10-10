<?php

declare(strict_types=1);

namespace Tests\Feature\Financial;

use App\Models\User;
use App\Models\UserBankAccount;
use App\Models\UserNotification;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Queue;
use Laratrust\Models\Permission;
use Laratrust\Models\Role;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Activitylog\Models\Activity;
use Tests\Concerns\GrantsAdminPermissions;
use Tests\TestCase;

/**
 * علی ۱۴۰۵/۰۷/۱۹: کاربر حسابِ بانکیِ ثبت‌شده‌اش را به هیچ راهی حذف نمی‌کند؛ فقط بک‌آفیس،
 * نرم، با ردِ ممیزی و اعلان به کاربر — و نه وقتی برداشتی به آن حساب در انتظار است.
 */
class BankAccountDeletionTest extends TestCase
{
    use DatabaseTransactions, GrantsAdminPermissions;

    private const REASON = 'درخواست کتبی کاربر در تیکت ۱۲۳';

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    private function account(User $owner, bool $default = false): UserBankAccount
    {
        $account = UserBankAccount::create([
            UserBankAccount::USER_ID => $owner->id,
            UserBankAccount::BANK_NAME => 'ملت',
            UserBankAccount::ACCOUNT_HOLDER_NAME => 'مالک',
            UserBankAccount::SHABA => 'IR'.str_pad((string) ++$this->seq, 24, '3', STR_PAD_LEFT),
            UserBankAccount::IS_DEFAULT => $default,
        ]);
        $account->forceFill([UserBankAccount::STATUS => UserBankAccount::STATUS_APPROVED])->save();

        return $account;
    }

    private function admin(int $level = User::ADMIN_LEVEL_MANAGER): User
    {
        $admin = User::factory()->create();
        $admin->addRole(Role::firstOrCreate(['name' => 'admin'], ['display_name' => 'Admin']));
        $this->grantAllAdminPermissions($admin);

        return $this->setAdminLevel($admin, $level);
    }

    private function adminDelete(User $owner, UserBankAccount $account, array $body = ['reason' => self::REASON])
    {
        return $this->deleteJson("/api/admin/users/{$owner->id}/bank-accounts/{$account->id}", $body);
    }

    // ── سمتِ کاربر ──────────────────────────────────────────────────────────

    #[Test]
    public function nobody_deletes_through_the_user_route_not_the_owner_not_an_admin(): void
    {
        $owner = User::factory()->create();
        $account = $this->account($owner);

        foreach ([$owner, $this->admin(User::ADMIN_LEVEL_OWNER)] as $actor) {
            Sanctum::actingAs($actor);
            $this->deleteJson("/api/profile/financial/accounts/{$account->id}")
                ->assertForbidden()
                ->assertJsonPath('message', __('bank.delete_support_only'));
        }

        $this->assertNotSoftDeleted($account);
    }

    // ── بک‌آفیس ─────────────────────────────────────────────────────────────

    #[Test]
    public function the_back_office_soft_deletes_audits_and_tells_the_user(): void
    {
        $owner = User::factory()->create();
        $account = $this->account($owner);
        $admin = $this->admin();

        Sanctum::actingAs($admin);
        $this->adminDelete($owner, $account)
            ->assertOk()
            ->assertJsonPath('message', __('bank.admin_delete_success'));

        $this->assertSoftDeleted($account);

        $audit = Activity::query()
            ->where('subject_type', User::class)->where('subject_id', $owner->id)
            ->where('causer_id', $admin->id)->latest('id')->first();
        $this->assertNotNull($audit, 'ردِ ممیزی روی کاربر لازم است.');
        $this->assertSame($account->id, $audit->properties['bank_account_id']);
        $this->assertSame(self::REASON, $audit->properties['reason']);

        $note = UserNotification::query()->where(UserNotification::USER_ID, $owner->id)->latest('id')->first();
        $this->assertNotNull($note, 'کاربر باید در برنامه باخبر شود.');
        $this->assertSame(__('bank.admin_deleted_subject'), $note->{UserNotification::SUBJECT});
        $this->assertStringContainsString('ملت', $note->{UserNotification::BODY});
        $this->assertStringContainsString(enToFa(substr($account->shaba, -4)), $note->{UserNotification::BODY});
    }

    #[Test]
    public function the_default_is_cleared_and_not_silently_moved_to_another_account(): void
    {
        $owner = User::factory()->create();
        $default = $this->account($owner, default: true);
        $other = $this->account($owner);

        Sanctum::actingAs($this->admin());
        $this->adminDelete($owner, $default)->assertOk();

        $this->assertFalse((bool) UserBankAccount::withTrashed()->find($default->id)->is_default);
        $this->assertFalse((bool) $other->refresh()->is_default, 'پیش‌فرضِ تازه را خودِ کاربر انتخاب می‌کند.');
    }

    #[Test]
    public function a_pending_withdrawal_to_the_account_blocks_deletion(): void
    {
        $owner = User::factory()->create();
        $account = $this->account($owner);
        $wallet = Wallet::create([Wallet::USER_ID => $owner->id, Wallet::BALANCE => 0, Wallet::BALANCE_HOLD => 0]);
        $tx = $wallet->transactions()->create([
            WalletTransaction::AMOUNT => 500_000,
            WalletTransaction::TYPE => WalletTransaction::TYPE_WITHDRAW,
            WalletTransaction::STATUS => WalletTransaction::STATUS_PENDING,
            WalletTransaction::DESCRIPTION => 'withdraw',
            WalletTransaction::META => ['bank_account_id' => $account->id, 'settlement' => ['state' => 'awaiting_approval']],
        ]);

        Sanctum::actingAs($this->admin());
        $this->adminDelete($owner, $account)
            ->assertStatus(422)
            ->assertJsonPath('message', __('bank.admin_delete_pending_withdrawal'));
        $this->assertNotSoftDeleted($account);

        // تعیین‌تکلیف شد → حذف آزاد است.
        $tx->forceFill([WalletTransaction::STATUS => WalletTransaction::STATUS_CONFIRMED])->save();
        $this->adminDelete($owner, $account)->assertOk();
        $this->assertSoftDeleted($account);
    }

    #[Test]
    public function the_gate_is_manage_financial_and_level_two_and_the_reason_is_required(): void
    {
        $owner = User::factory()->create();
        $account = $this->account($owner);

        Sanctum::actingAs($this->admin(User::ADMIN_LEVEL_AGENT));
        $this->adminDelete($owner, $account)->assertForbidden();

        $usersOnly = $this->admin();
        $usersOnly->syncPermissions([Permission::firstOrCreate(['name' => 'manage-users'], ['display_name' => 'users'])->id]);
        Sanctum::actingAs($usersOnly);
        $this->getJson("/api/admin/users/{$owner->id}/bank-accounts")->assertForbidden();

        Sanctum::actingAs($this->admin());
        $this->adminDelete($owner, $account, [])->assertStatus(422)->assertJsonValidationErrors('reason');

        $this->assertNotSoftDeleted($account);
    }

    #[Test]
    public function another_users_account_is_not_reachable_under_this_user(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        $account = $this->account($stranger);

        Sanctum::actingAs($this->admin());
        $this->adminDelete($owner, $account)->assertNotFound();
        $this->assertNotSoftDeleted($account);
    }

    #[Test]
    public function the_admin_list_keeps_deleted_accounts_with_their_date(): void
    {
        $owner = User::factory()->create();
        $live = $this->account($owner);
        $gone = $this->account($owner);

        Sanctum::actingAs($this->admin());
        $this->adminDelete($owner, $gone)->assertOk();

        $rows = collect($this->getJson("/api/admin/users/{$owner->id}/bank-accounts")->assertOk()->json('data'))->keyBy('id');
        $this->assertNull($rows[$live->id]['deleted_at']);
        $this->assertNotNull($rows[$gone->id]['deleted_at']);
        $this->assertSame($gone->shaba, $rows[$gone->id]['shaba'], 'ادمین شبای کامل را می‌بیند.');
    }

    // ── حسابِ حذف‌شده برای کاربر وجود ندارد ────────────────────────────────

    #[Test]
    public function a_deleted_account_is_gone_from_the_users_list_and_cannot_receive_a_withdrawal(): void
    {
        $owner = User::factory()->create();
        Wallet::create([Wallet::USER_ID => $owner->id, Wallet::BALANCE => 5_000_000, Wallet::BALANCE_HOLD => 0]);
        $live = $this->account($owner);
        $gone = $this->account($owner);

        Sanctum::actingAs($this->admin());
        $this->adminDelete($owner, $gone)->assertOk();

        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($owner->fresh());
        $ids = collect($this->getJson('/api/profile/financial/accounts')->assertOk()->json('data'))->pluck('id');
        $this->assertContains($live->id, $ids);
        $this->assertNotContains($gone->id, $ids);

        // قاعده‌ی `exists` کوئریِ خام است و scopeِ SoftDeletes را ندارد — باید ۴۲۲ِ اعتبارسنجی بدهد.
        $this->postJson('/api/profile/financial/wallet/withdraw', [
            'amount' => (int) config('financial.withdrawal_min') + 1_000,
            'user_bank_account_id' => $gone->id,
        ])->assertStatus(422)->assertJsonValidationErrors('user_bank_account_id');

        $this->assertSame(0, WalletTransaction::query()->where(WalletTransaction::TYPE, WalletTransaction::TYPE_WITHDRAW)
            ->where(WalletTransaction::META.'->bank_account_id', $gone->id)->count());
    }
}
