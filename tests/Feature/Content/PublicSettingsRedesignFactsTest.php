<?php

declare(strict_types=1);

namespace Tests\Feature\Content;

use App\Http\Requests\Financial\WalletWithdrawalRequest;
use App\Models\Setting;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Validator;
use Laratrust\Models\Role;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\GrantsAdminPermissions;
use Tests\TestCase;

/**
 * عددها و فلگ‌هایی که سایتِ بازطراحی‌شده نباید هاردکد کند (ریدیزاین، BE1):
 * مدتِ خروجِ خودکار پس از بی‌کاری (تنظیمِ ادمین، پیش‌فرض یک هفته)، فلگِ
 * `partner_coupons`، کف/سقفِ شارژ به‌ازای هر روش و کفِ برداشت.
 */
class PublicSettingsRedesignFactsTest extends TestCase
{
    use DatabaseTransactions, GrantsAdminPermissions;

    protected function setUp(): void
    {
        parent::setUp();
        Setting::where(Setting::KEY, 'session_idle_logout_minutes')->delete();
        Cache::forget(Setting::CACHE_KEY);
    }

    private function publicSettings(): array
    {
        return $this->getJson('/api/settings/public')->assertOk()->json('data');
    }

    #[Test]
    public function the_idle_logout_defaults_to_one_week_and_follows_the_admin_setting(): void
    {
        $this->assertSame(10080, $this->publicSettings()['session_idle_logout_minutes']);

        $admin = User::factory()->create();
        $admin->addRole(Role::firstOrCreate(['name' => 'admin'], ['display_name' => 'Admin']));
        $this->grantAllAdminPermissions($admin);
        Sanctum::actingAs($admin);

        $this->putJson('/api/admin/settings', ['settings' => ['session_idle_logout_minutes' => 'abc']])
            ->assertStatus(422);
        // بلندتر از عمرِ توکن معنا ندارد — توکن زودتر می‌میرد.
        $this->putJson('/api/admin/settings', ['settings' => ['session_idle_logout_minutes' => (string) (config('sanctum.expiration') + 1)]])
            ->assertStatus(422);

        $this->putJson('/api/admin/settings', ['settings' => ['session_idle_logout_minutes' => '60']])->assertOk();

        $this->assertSame(60, $this->publicSettings()['session_idle_logout_minutes']);
    }

    #[Test]
    public function the_partner_coupons_flag_is_public_in_both_states(): void
    {
        config(['features.partner_coupons' => false]);
        $this->assertFalse($this->publicSettings()['features']['partner_coupons']);

        config(['features.partner_coupons' => true]);
        $this->assertTrue($this->publicSettings()['features']['partner_coupons']);
    }

    #[Test]
    public function deposit_limits_and_the_withdrawal_minimum_come_from_config(): void
    {
        config([
            'financial.deposit_min' => 12_345,
            'financial.deposit_max_podium' => 3_000_000_000,
            'financial.withdrawal_min' => 70_000,
        ]);

        $data = $this->publicSettings();
        $methods = collect($data['wallet_deposit_methods'])->keyBy('key');

        $this->assertSame(12_345, $methods['bank_transfer']['min']);
        $this->assertNull($methods['bank_transfer']['max']);
        $this->assertSame(12_345, $methods['podium']['min']);
        $this->assertSame(3_000_000_000, $methods['podium']['max']);
        // سقفِ درگاه کارت از `shaparak_value`ِ تنظیماتِ فروش است (BE3)، نه از config/financial.php.
        $this->assertSame(2_000_000_000, $methods['shaparak']['max']);
        $this->assertSame(70_000, $data['wallet_withdrawal_min']);

        // عددی که سایت نشان می‌دهد همان است که درخواستِ برداشت اعمال می‌کند.
        $user = User::factory()->create();
        Wallet::create([Wallet::USER_ID => $user->id, Wallet::BALANCE => 1_000_000_000, Wallet::BALANCE_HOLD => 0]);
        Sanctum::actingAs($user);
        $amountRules = ['amount' => (new WalletWithdrawalRequest)->rules()['amount']];
        $this->assertTrue(Validator::make(['amount' => 60_000], $amountRules)->fails());
        $this->assertFalse(Validator::make(['amount' => 70_000], $amountRules)->fails());
    }
}
