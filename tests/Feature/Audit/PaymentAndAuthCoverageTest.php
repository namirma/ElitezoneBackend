<?php

namespace Tests\Feature\Audit;

use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Coverage for previously-untested critical paths (audit suggestion S6):
 * password reset flow + payment gateway callback.
 */
#[Group('audit-fixes')]
class PaymentAndAuthCoverageTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * Password reset happy path: forget-password issues the OTP, change-password
     * with the (testing-env) debug code 111111 updates the password and returns a token.
     */
    public function test_password_reset_happy_path(): void
    {
        $user = User::factory()->create([
            'is_active' => true,
            'password' => Hash::make('old-password'),
        ]);

        $this->postJson('/api/authenticate/forget-password', [
            'username' => $user->mobile,
        ])->assertStatus(200);

        // In the testing environment generateVerifyCode() returns the fixed debug OTP.
        $this->postJson('/api/authenticate/change-password', [
            'username' => $user->mobile,
            'code' => '111111',
            'password' => 'brand-new-pass-123',
            'password_confirmation' => 'brand-new-pass-123',
        ])->assertStatus(200)
            ->assertJsonStructure(['status', 'data' => ['token']]);

        $this->assertTrue(Hash::check('brand-new-pass-123', $user->fresh()->password));
    }

    /** A wrong reset code must be rejected (400) and must not change the password. */
    public function test_change_password_rejects_wrong_code(): void
    {
        $user = User::factory()->create([
            'is_active' => true,
            'password' => Hash::make('old-password'),
        ]);

        $this->postJson('/api/authenticate/forget-password', ['username' => $user->mobile])
            ->assertStatus(200);

        $this->postJson('/api/authenticate/change-password', [
            'username' => $user->mobile,
            'code' => '000000',
            'password' => 'should-not-apply-123',
            'password_confirmation' => 'should-not-apply-123',
        ])->assertStatus(400);

        $this->assertTrue(Hash::check('old-password', $user->fresh()->password));
    }

    /** Payment callback for an unknown transaction must redirect gracefully (not 500). */
    public function test_payment_callback_unknown_transaction_redirects_gracefully(): void
    {
        $response = $this->post('/api/payment/callback', [
            'transaction_id' => 999999999,
            'Authority' => 'fake-authority',
            'Status' => 'OK',
        ]);

        $response->assertStatus(302);
        $this->assertStringContainsString('status=failed', $response->headers->get('Location'));
    }

    /**
     * A callback for an already-settled (non-pending) transaction must not re-process
     * it — the pending-only lookup returns "not found" and redirects gracefully.
     * This guards the idempotency of the gateway callback.
     */
    public function test_payment_callback_is_idempotent_for_settled_transaction(): void
    {
        $user = User::factory()->create();
        $wallet = Wallet::create([Wallet::USER_ID => $user->id, Wallet::BALANCE => 0, Wallet::BALANCE_HOLD => 0]);

        $tx = WalletTransaction::create([
            'wallet_id' => $wallet->id,
            'amount' => 500000,
            'type' => WalletTransaction::TYPE_DEPOSIT,
            'status' => WalletTransaction::STATUS_CONFIRMED, // already settled
            'description' => 'already settled',
        ]);

        $response = $this->post('/api/payment/callback', [
            'transaction_id' => $tx->id,
            'Authority' => 'fake-authority',
            'Status' => 'OK',
        ]);

        $response->assertStatus(302);
        // Balance must be unchanged (no double credit).
        $this->assertEqualsWithDelta(0, (float) $wallet->fresh()->balance, 0.01);
    }
}
