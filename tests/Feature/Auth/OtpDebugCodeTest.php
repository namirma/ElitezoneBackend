<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * کدِ ثابتِ OTP اختیاری است، نه پیش‌فرض.
 *
 * `auth.otp_debug_code` قبلاً پیش‌فرضِ '111111' داشت و `generateVerifyCode()` در
 * هر محیطِ غیرپروداکشن آن را برمی‌گرداند. استیجینگ با `APP_ENV=local` روی کاربرانِ
 * واقعی اجرا می‌شود؛ پس هر کس موبایلی را می‌دانست با 111111 توکنِ آن حساب را می‌گرفت.
 * حالا کدِ ثابت فقط وقتی به کار می‌رود که متغیر ست باشد **و** محیط production نباشد.
 * همین helper را `BeneficiaryController` و `SupplierDriverController` هم صدا می‌زنند.
 */
class OtpDebugCodeTest extends TestCase
{
    use DatabaseTransactions;

    #[Test]
    public function without_a_configured_code_the_otp_is_random_even_outside_production(): void
    {
        $this->app->detectEnvironment(fn () => 'local');

        foreach ([null, ''] as $unset) {
            config(['auth.otp_debug_code' => $unset]);

            $this->assertRandom();
        }
    }

    #[Test]
    public function a_configured_code_is_used_outside_production(): void
    {
        $this->app->detectEnvironment(fn () => 'local');
        config(['auth.otp_debug_code' => '482913']);

        $this->assertSame(482913, generateVerifyCode());
    }

    #[Test]
    public function production_is_random_even_when_a_code_is_configured(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        config(['auth.otp_debug_code' => '482913']);

        $this->assertRandom();
    }

    /** سناریوی حمله روی استیجینگ: env=local، متغیر ست نشده، 111111 دیگر وارد نمی‌کند. */
    #[Test]
    public function the_old_default_no_longer_logs_anyone_in_on_a_local_env(): void
    {
        Cache::flush();
        Queue::fake();
        $this->app->detectEnvironment(fn () => 'local');
        config(['auth.otp_debug_code' => null]);

        User::factory()->create([User::MOBILE => '09125550001', User::PASSWORD => null]);

        $this->postJson('/api/authenticate/check-user', ['username' => '09125550001'])->assertOk();

        $res = $this->postJson('/api/authenticate/verify-code', [
            'username' => '09125550001',
            'code' => '111111',
        ]);

        $this->assertNotSame(200, $res->status());
        $this->assertNull($res->json('data.token'));
    }

    private function assertRandom(): void
    {
        $codes = array_map(fn () => generateVerifyCode(), range(1, 5));

        foreach ($codes as $code) {
            $this->assertGreaterThanOrEqual(100000, $code);
            $this->assertLessThanOrEqual(999999, $code);
        }

        // پنج کدِ تصادفیِ شش‌رقمی عملاً هرگز همه برابر نیستند؛ کدِ ثابت همیشه هست.
        $this->assertGreaterThan(1, count(array_unique($codes)));
    }
}
