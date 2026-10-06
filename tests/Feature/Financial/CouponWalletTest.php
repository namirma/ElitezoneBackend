<?php

declare(strict_types=1);

namespace Tests\Feature\Financial;

use App\Models\Role;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\FundsCouponWallet;
use Tests\Concerns\GrantsAdminPermissions;
use Tests\TestCase;

/**
 * «کیف پول کوپن‌های تخفیف» — مدیریت، دورِ پنجم (۱۴۰۵/۰۷/۱۴): «شرکت در بک‌آفیس مبلغ و منبعِ مالی‌اش را
 * جداگانه تعیین و شارژ می‌کند؛ مدیریت و دسترسی فقط از بک‌آفیس.» شارژ از کیف پولِ سازه (WSSG) است و
 * هر جابه‌جایی دو ردیفِ دفتر + ردِ ممیزی دارد.
 */
class CouponWalletTest extends TestCase
{
    use DatabaseTransactions;
    use FundsCouponWallet;
    use GrantsAdminPermissions;

    private User $system;

    private User $couponWallet;

    protected function setUp(): void
    {
        parent::setUp();

        $this->system = User::factory()->create();
        config(['financial.system_user_id' => $this->system->id, 'financial.vat_user_id' => $this->system->id]);
        Wallet::create([Wallet::USER_ID => $this->system->id, Wallet::BALANCE => 50_000_000, Wallet::BALANCE_HOLD => 0]);

        $this->couponWallet = $this->fundCouponWallet(0);
    }

    private function admin(int $level = User::ADMIN_LEVEL_OWNER): User
    {
        $admin = User::factory()->create();
        $admin->addRole(Role::firstOrCreate(['name' => 'admin'], ['display_name' => 'admin']));
        $this->grantAllAdminPermissions($admin);
        // پس از grantAll — آن سطحِ مالک را می‌نشاند.
        $admin->forceFill([User::ADMIN_LEVEL => $level])->save();

        return $admin;
    }

    private function balance(User $user): float
    {
        return (float) Wallet::where(Wallet::USER_ID, $user->id)->value(Wallet::BALANCE);
    }

    #[Test]
    public function the_owner_funds_the_coupon_wallet_from_the_saze_wallet_with_a_ledger_trail(): void
    {
        Sanctum::actingAs($admin = $this->admin());

        $this->postJson('/api/admin/coupon-wallet/fund', ['amount' => 10_000_000, 'note' => 'بودجه‌ی کمپینِ مهر'])
            ->assertOk()
            ->assertJsonPath('data.balance', '10000000');

        $this->assertEqualsWithDelta(40_000_000, $this->balance($this->system), 0.0);
        $this->assertEqualsWithDelta(10_000_000, $this->balance($this->couponWallet), 0.0);

        $legs = WalletTransaction::whereIn('wallet_id', Wallet::whereIn(Wallet::USER_ID, [$this->system->id, $this->couponWallet->id])->pluck('id'))
            ->where('meta->kind', 'coupon_wallet_transfer')->get();
        $this->assertCount(2, $legs);
        $this->assertSame($admin->id, (int) $legs->first()->meta['admin_id']);
        $this->assertStringContainsString('بودجه‌ی کمپینِ مهر', (string) $legs->first()->description);

        $this->getJson('/api/admin/coupon-wallet')->assertOk()
            ->assertJsonPath('data.configured', true)
            ->assertJsonPath('data.balance', '10000000');

        // برگشتِ مانده به سازه، و بیشتر از مانده رد می‌شود.
        $this->postJson('/api/admin/coupon-wallet/defund', ['amount' => 4_000_000, 'note' => 'پایانِ کمپین'])->assertOk();
        $this->assertEqualsWithDelta(6_000_000, $this->balance($this->couponWallet), 0.0);
        $this->postJson('/api/admin/coupon-wallet/defund', ['amount' => 7_000_000, 'note' => 'بیش از مانده'])->assertStatus(422);
        $this->assertEqualsWithDelta(6_000_000, $this->balance($this->couponWallet), 0.0);
    }

    #[Test]
    public function funding_beyond_the_saze_wallet_is_refused_without_moving_money(): void
    {
        Sanctum::actingAs($this->admin());

        $this->postJson('/api/admin/coupon-wallet/fund', ['amount' => 60_000_000, 'note' => 'بیش از موجودی'])->assertStatus(422);

        $this->assertEqualsWithDelta(50_000_000, $this->balance($this->system), 0.0);
        $this->assertEqualsWithDelta(0.0, $this->balance($this->couponWallet), 0.0);
    }

    #[Test]
    public function only_the_owner_level_moves_money_and_a_note_is_required(): void
    {
        Sanctum::actingAs($this->admin(User::ADMIN_LEVEL_MANAGER));
        $this->getJson('/api/admin/coupon-wallet')->assertOk();
        $this->postJson('/api/admin/coupon-wallet/fund', ['amount' => 1_000, 'note' => 'مدیر'])->assertForbidden();

        Sanctum::actingAs($this->admin());
        $this->postJson('/api/admin/coupon-wallet/fund', ['amount' => 1_000])->assertStatus(422)->assertJsonValidationErrors(['note']);
        $this->assertEqualsWithDelta(0.0, $this->balance($this->couponWallet), 0.0);
    }
}
