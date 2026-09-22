<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laratrust\Models\Role;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\GrantsAdminPermissions;
use Tests\TestCase;

/**
 * قراردادِ صفحه‌ی «کیف پول»ِ بک‌آفیس (۱۴۰۵/۰۶/۳۰) — دو فیلدِ خواندنی که پیش از
 * این هیچ endpointی نمی‌داد: شناسه‌ی کاربرِ کیف‌های پلتفرم (WSSG/WVAT) در
 * `financial/health`، و موجودیِ کیف پول روی `UserResource` وقتی لود شده.
 */
class AdminWalletPageContractTest extends TestCase
{
    use DatabaseTransactions, GrantsAdminPermissions;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create();
        $this->admin->addRole(Role::firstOrCreate(['name' => 'admin'], ['display_name' => 'Admin']));
        $this->grantAllAdminPermissions($this->admin);
    }

    #[Test]
    public function financial_health_names_the_user_behind_each_platform_wallet(): void
    {
        $system = User::factory()->create();
        $vat = User::factory()->create();
        config(['financial.system_user_id' => $system->id, 'financial.vat_user_id' => $vat->id]);
        Wallet::create([Wallet::USER_ID => $system->id, Wallet::BALANCE => 1_000, Wallet::BALANCE_HOLD => 50]);
        Wallet::create([Wallet::USER_ID => $vat->id, Wallet::BALANCE => 7, Wallet::BALANCE_HOLD => 3]);

        Sanctum::actingAs($this->admin);
        $data = $this->getJson('/api/admin/financial/health')->assertOk()->json('data');

        $this->assertSame($system->id, $data['system_wallet']['user_id']);
        $this->assertSame($vat->id, $data['vat_wallet']['user_id']);
        $this->assertEquals(1_000, $data['system_wallet']['balance']);
        $this->assertEquals(3, $data['vat_wallet']['balance_hold']);
    }

    #[Test]
    public function admin_user_search_carries_the_wallet_when_it_exists_and_null_when_not(): void
    {
        $withWallet = User::factory()->create([User::MOBILE => '09120001234']);
        Wallet::create([Wallet::USER_ID => $withWallet->id, Wallet::BALANCE => 250_000, Wallet::BALANCE_HOLD => 10_000]);
        $without = User::factory()->create([User::MOBILE => '09120005678']);

        Sanctum::actingAs($this->admin);

        $row = collect($this->getJson('/api/admin/search/users?query=0912000123')->assertOk()->json('data'))
            ->firstWhere('id', $withWallet->id);
        $this->assertSame('250000', $row['wallet']['balance']);
        $this->assertSame('10000', $row['wallet']['balance_hold']);

        $row = collect($this->getJson('/api/admin/search/users?query=0912000567')->assertOk()->json('data'))
            ->firstWhere('id', $without->id);
        $this->assertArrayHasKey('wallet', $row, 'رابطه لود شده، پس کلید هست');
        $this->assertNull($row['wallet'], 'کیفِ ساخته‌نشده null است، نه صفر');
    }

    #[Test]
    public function a_hold_release_row_carries_a_persian_type_label(): void
    {
        $user = User::factory()->create();
        $wallet = Wallet::create([Wallet::USER_ID => $user->id, Wallet::BALANCE => 0, Wallet::BALANCE_HOLD => 0]);
        $row = WalletTransaction::create([
            WalletTransaction::WALLET_ID => $wallet->id,
            WalletTransaction::AMOUNT => 1_000,
            WalletTransaction::TYPE => WalletTransaction::TYPE_HOLD_RELEASE,
            WalletTransaction::STATUS => WalletTransaction::STATUS_CONFIRMED,
            WalletTransaction::DESCRIPTION => 'test',
        ]);

        Sanctum::actingAs($this->admin);
        $data = $this->getJson("/api/admin/wallet/transactions/{$row->id}")->assertOk()->json('data');

        $this->assertSame('hold_release', $data['type_key']);
        $this->assertSame('آزادسازی هولد', $data['type'], 'اسلاگِ لاتین نباید به جدولِ فارسی برسد');
    }

    #[Test]
    public function the_wallet_is_absent_when_the_relation_is_not_loaded(): void
    {
        $user = User::factory()->create();
        Wallet::create([Wallet::USER_ID => $user->id, Wallet::BALANCE => 1, Wallet::BALANCE_HOLD => 0]);

        // `GET /profile` برای خودِ شخص wallet را لود نمی‌کند — قراردادِ سایت دست نمی‌خورد.
        Sanctum::actingAs($user);
        $data = $this->getJson('/api/profile')->assertOk()->json('data');

        $this->assertArrayNotHasKey('wallet', $data);
    }
}
