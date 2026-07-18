<?php

namespace Tests\Feature\Admin;

use App\Models\Order;
use App\Models\PodiumDepositRecord;
use App\Models\Role;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * پین‌کردن سخت‌سازی‌های پنل ادمین:
 *  - اجرای users.is_active (middleware سراسری + حذف توکن هنگام غیرفعال‌سازی + گارد self)
 *  - idempotency شارژ دستی واریز پادیوم (هرگز دو بار credit نشود)
 *  - ماشین وضعیت سفارش: pending → processing فقط برای سفارش پرداخت‌شده
 *  - endpoint سهم‌های الیت یار (رگرسیون import جاافتاده — قبلاً Class not found می‌داد)
 */
class AdminHardeningTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $adminRole = Role::firstOrCreate(['name' => 'admin'], [
            'display_name' => 'Admin',
            'description' => 'Administrator',
        ]);

        $this->admin = User::factory()->create();
        $this->admin->addRole($adminRole);
    }

    // ── users.is_active ──────────────────────────────────────────────────────

    public function test_deactivated_user_is_blocked_by_the_global_middleware(): void
    {
        $user = User::factory()->create([User::IS_ACTIVE => false]);
        Sanctum::actingAs($user);

        $this->getJson('/api/user')->assertForbidden();
    }

    public function test_active_user_passes_the_middleware(): void
    {
        $user = User::factory()->create([User::IS_ACTIVE => true]);
        Sanctum::actingAs($user);

        $this->getJson('/api/user')->assertOk();
    }

    public function test_deactivating_a_user_revokes_their_tokens(): void
    {
        $user = User::factory()->create([User::IS_ACTIVE => true]);
        $user->createToken('t1');
        $user->createToken('t2');

        Sanctum::actingAs($this->admin);
        $this->postJson("/api/admin/users/{$user->id}/toggle-status")->assertOk();

        $this->assertFalse((bool) $user->fresh()->{User::IS_ACTIVE});
        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_admin_cannot_deactivate_their_own_account(): void
    {
        Sanctum::actingAs($this->admin);

        $this->postJson("/api/admin/users/{$this->admin->id}/toggle-status")
            ->assertStatus(422);

        $this->assertTrue((bool) $this->admin->fresh()->{User::IS_ACTIVE});
    }

    public function test_deactivated_user_cannot_request_a_login_otp(): void
    {
        $user = User::factory()->create([User::IS_ACTIVE => false]);

        $this->postJson('/api/authenticate/check-user', ['username' => $user->mobile])
            ->assertForbidden();
    }

    // ── Podium manual credit — double-credit guard ───────────────────────────

    public function test_podium_manual_credit_is_never_applied_twice(): void
    {
        $customer = User::factory()->create();
        Wallet::create([
            Wallet::USER_ID => $customer->id,
            Wallet::BALANCE => 0,
            Wallet::BALANCE_HOLD => 0,
        ]);

        $record = PodiumDepositRecord::create([
            PodiumDepositRecord::DOC_NUMBER => 'TEST-'.uniqid(),
            PodiumDepositRecord::CREDITOR_AMOUNT => 5_000_000,
        ]);

        Sanctum::actingAs($this->admin);

        $this->postJson("/api/admin/financial/podium-deposits/{$record->id}/credit", [
            'user_id' => $customer->id,
        ])->assertOk();

        // فراخوانی دوم — رکورد processed شده؛ نباید دوباره credit شود.
        $this->postJson("/api/admin/financial/podium-deposits/{$record->id}/credit", [
            'user_id' => $customer->id,
        ])->assertStatus(422);

        $this->assertSame(5_000_000.0, (float) $customer->wallet->fresh()->{Wallet::BALANCE});
        $this->assertSame(1, $customer->wallet->transactions()->count());
    }

    // ── Order state machine — pending → processing needs payment ────────────

    public function test_admin_cannot_move_an_unpaid_pending_order_to_processing(): void
    {
        $order = $this->makeOrder(Order::STATUS_PENDING, Order::PAYMENT_PENDING);

        Sanctum::actingAs($this->admin);

        $this->postJson("/api/admin/orders/{$order->id}/status", [
            'status' => Order::STATUS_PROCESSING,
        ])->assertStatus(422);

        $this->assertSame(Order::STATUS_PENDING, $order->fresh()->{Order::STATUS});
    }

    public function test_admin_can_move_a_paid_pending_order_to_processing(): void
    {
        $order = $this->makeOrder(Order::STATUS_PENDING, Order::PAYMENT_PAID);

        Sanctum::actingAs($this->admin);

        $this->postJson("/api/admin/orders/{$order->id}/status", [
            'status' => Order::STATUS_PROCESSING,
        ])->assertOk();

        $this->assertSame(Order::STATUS_PROCESSING, $order->fresh()->{Order::STATUS});
    }

    // ── AdminFinancialController — regression for the missing import ─────────

    public function test_expert_shares_endpoint_responds_without_fatal(): void
    {
        Sanctum::actingAs($this->admin);

        $this->getJson('/api/admin/financial/company/expert-shares')->assertOk();
        $this->getJson('/api/admin/financial/expert/documents')->assertOk();
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function makeOrder(string $status, string $paymentStatus): Order
    {
        $buyer = User::factory()->create();

        return Order::create([
            Order::PLACER_USER_ID => $buyer->id,
            Order::ORDER_NUMBER => 'ADM-'.now()->format('YmdHis').rand(100, 999),
            Order::STATUS => $status,
            Order::PAYMENT_STATUS => $paymentStatus,
            Order::FINAL_AMOUNT => 100_000_000,
        ]);
    }
}
