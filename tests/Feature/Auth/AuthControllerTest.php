<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Support\OtpSecret;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * تست‌های جریان OTP (بدون Podium) — checkUserAndSendCode + verifyCode
 *
 * هر تست از موبایل منحصربه‌فرد استفاده می‌کند تا rate-limiter / OTP lockout
 * از تست قبلی روی هم تأثیر نگذارد.
 */
class AuthControllerTest extends TestCase
{
    use DatabaseTransactions;

    // ── checkUserAndSendCode ───────────────────────────────────────────────

    public function test_check_user_returns_404_for_unknown_mobile(): void
    {
        $this->postJson('/api/authenticate/check-user', ['username' => '09100000001'])
            ->assertStatus(404)
            ->assertJsonPath('status', false);
    }

    public function test_check_user_returns_register_action_for_incomplete_profile(): void
    {
        // کاربری که national_code ندارد باید به صفحه register هدایت شود
        User::factory()->create(['mobile' => '09100000002', 'national_code' => null]);

        $this->postJson('/api/authenticate/check-user', ['username' => '09100000002'])
            ->assertStatus(404)
            ->assertJsonPath('errors.action', 'register');
    }

    public function test_check_user_returns_login_with_password_when_password_is_set(): void
    {
        User::factory()->create([
            'mobile' => '09100000003',
            'national_code' => '1234567890',
            'birth_date' => '13800101',
            'password' => bcrypt('secret'),
        ]);

        $this->postJson('/api/authenticate/check-user', ['username' => '09100000003'])
            ->assertStatus(200)
            ->assertJsonPath('data.action', 'login_with_password');
    }

    public function test_check_user_sends_otp_to_existing_user(): void
    {
        Queue::fake();

        User::factory()->create([
            'mobile' => '09100000004',
            'national_code' => '1234567890',
            'birth_date' => '13800101',
        ]);

        $this->postJson('/api/authenticate/check-user', ['username' => '09100000004'])
            ->assertStatus(200)
            ->assertJsonPath('status', true);
    }

    // ── verifyCode ────────────────────────────────────────────────────────

    public function test_verify_code_returns_token_on_success(): void
    {
        $secret = '654321';

        User::factory()->create([
            'mobile' => '09100000010',
            'national_code' => '1234567890',
            'birth_date' => '13800101',
            'secret' => OtpSecret::hash($secret),
            'secret_expires_at' => now()->addMinutes(5),
        ]);

        $this->postJson('/api/authenticate/verify-code', [
            'username' => '09100000010',
            'code' => $secret,
        ])
            ->assertStatus(200)
            ->assertJsonPath('status', true)
            ->assertJsonStructure(['data' => ['token']]);
    }

    public function test_verify_code_rejects_wrong_otp(): void
    {
        User::factory()->create([
            'mobile' => '09100000011',
            'national_code' => '1234567890',
            'birth_date' => '13800101',
            'secret' => OtpSecret::hash('111111'),
            'secret_expires_at' => now()->addMinutes(5),
        ]);

        $this->postJson('/api/authenticate/verify-code', [
            'username' => '09100000011',
            'code' => '999999',
        ])
            ->assertStatus(404)
            ->assertJsonPath('status', false);
    }

    public function test_verify_code_rejects_expired_otp(): void
    {
        $secret = '123456';

        User::factory()->create([
            'mobile' => '09100000012',
            'national_code' => '1234567890',
            'birth_date' => '13800101',
            'secret' => OtpSecret::hash($secret),
            'secret_expires_at' => now()->subMinutes(1),
        ]);

        $this->postJson('/api/authenticate/verify-code', [
            'username' => '09100000012',
            'code' => $secret,
        ])
            ->assertStatus(404);
    }

    public function test_otp_is_single_use_replay_is_rejected(): void
    {
        $secret = '246810';

        User::factory()->create([
            'mobile' => '09100000013',
            'national_code' => '1234567890',
            'birth_date' => '13800101',
            'secret' => OtpSecret::hash($secret),
            'secret_expires_at' => now()->addMinutes(5),
        ]);

        // مصرف اول — موفق
        $this->postJson('/api/authenticate/verify-code', [
            'username' => '09100000013',
            'code' => $secret,
        ])->assertStatus(200);

        // replay — باید رد شود چون secret خالی شده
        $this->postJson('/api/authenticate/verify-code', [
            'username' => '09100000013',
            'code' => $secret,
        ])->assertStatus(404);
    }
}
