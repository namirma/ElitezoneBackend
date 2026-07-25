<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Support\OtpSecret;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * قفلِ تجمعیِ OTP و قفلِ ورود با رمز باید از هم جدا باشند.
 *
 * تا پیش از این هر دو مسیر روی کلید مشترکِ `otp_lock:{mobile}` می‌نشستند، پس
 * کسی که فقط شمارهٔ موبایلِ قربانی را می‌دانست می‌توانست با چند کدِ اشتباه
 * ورودِ *با رمزِ* او را هم قفل کند — DoS بدون نیاز به هیچ اطلاعات دیگری.
 */
class LockoutScopeIsolationTest extends TestCase
{
    use DatabaseTransactions;

    private const MOBILE = '09121110000';

    private const PASSWORD = 'correct-horse-8';

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        // ورود موفق، پر کردن پروفایل از پودیوم را به صف می‌سپارد؛ با صفِ sync
        // در تست، آن درخواستِ HTTP واقعاً اجرا می‌شد و هر تست را ده ثانیه کند می‌کرد.
        Queue::fake();

        config([
            'auth.otp_max_attempts' => 3,
            'auth.password_max_attempts' => 3,
            'auth.otp_lockout_minutes' => 10,
            'auth.password_lockout_minutes' => 10,
        ]);
    }

    private function user(): User
    {
        return User::factory()->create([
            User::MOBILE => self::MOBILE,
            User::NATIONAL_CODE => '1111111111',
            User::BIRTH_DATE => '13700101',
            User::PASSWORD => Hash::make(self::PASSWORD),
            User::SECRET => OtpSecret::hash('123456'),
            User::SECRET_EXPIRES_AT => now()->addMinutes(5),
        ]);
    }

    private function failOtp(int $times): void
    {
        for ($i = 0; $i < $times; $i++) {
            $this->postJson('/api/authenticate/verify-code', [
                'username' => self::MOBILE,
                'code' => '000000',
            ]);
        }
    }

    private function failPassword(int $times): void
    {
        for ($i = 0; $i < $times; $i++) {
            $this->postJson('/api/authenticate/password/check', [
                'username' => self::MOBILE,
                'password' => 'definitely-wrong',
            ]);
        }
    }

    #[Test]
    public function otp_failures_do_not_lock_password_login(): void
    {
        $this->user();

        $this->failOtp(3);

        // OTP قفل است …
        $this->postJson('/api/authenticate/verify-code', [
            'username' => self::MOBILE,
            'code' => '123456',
        ])->assertStatus(429);

        // … ولی ورود با رمزِ درست باید همچنان کار کند.
        $this->postJson('/api/authenticate/password/check', [
            'username' => self::MOBILE,
            'password' => self::PASSWORD,
        ])->assertOk();
    }

    #[Test]
    public function password_failures_do_not_lock_otp_verification(): void
    {
        $this->user();

        $this->failPassword(3);

        $this->postJson('/api/authenticate/password/check', [
            'username' => self::MOBILE,
            'password' => self::PASSWORD,
        ])->assertStatus(429);

        // تأیید کد نباید از شکستِ رمز آسیب ببیند.
        $this->postJson('/api/authenticate/verify-code', [
            'username' => self::MOBILE,
            'code' => '123456',
        ])->assertOk();
    }

    #[Test]
    public function the_password_lockout_still_engages_on_its_own_scope(): void
    {
        $this->user();

        $this->failPassword(3);

        $this->postJson('/api/authenticate/password/check', [
            'username' => self::MOBILE,
            'password' => self::PASSWORD,
        ])
            ->assertStatus(429)
            ->assertJsonPath('message', __('auth.too_many_password_attempts'));
    }

    #[Test]
    public function a_successful_password_login_clears_only_the_password_counter(): void
    {
        $this->user();

        $this->failOtp(2);
        $this->failPassword(2);

        $this->postJson('/api/authenticate/password/check', [
            'username' => self::MOBILE,
            'password' => self::PASSWORD,
        ])->assertOk();

        $this->assertNull(Cache::get('auth_fails:password:'.self::MOBILE));
        $this->assertSame(2, Cache::get('auth_fails:otp:'.self::MOBILE));
    }

    /**
     * استعلام هویتِ پودیوم نباید روی مسیر ورود بلاک کند — timeout پیش‌فرضش
     * ۳۰ ثانیه است و ورود بدونش هم کامل موفق می‌شود.
     */
    #[Test]
    public function otp_login_queues_the_podium_identity_sync_instead_of_blocking(): void
    {
        $user = $this->user();

        $this->postJson('/api/authenticate/verify-code', [
            'username' => self::MOBILE,
            'code' => '123456',
        ])->assertOk();

        Queue::assertPushed(
            \App\Jobs\Auth\SyncIdentityToProfileJob::class,
            fn ($job) => $job->userId === $user->id,
        );
    }
}
