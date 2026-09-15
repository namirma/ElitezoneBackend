<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Models\UserTwoFactor;
use App\Support\OtpSecret;
use App\Support\Totp;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * ورودِ دومرحله‌ای با TOTP — فعال‌سازی، گاردِ ورود، کدِ بازیابیِ یک‌بارمصرف،
 * سوختنِ چالش پس از پنج کدِ غلط، و خاموش‌کردن.
 */
class TwoFactorLoginTest extends TestCase
{
    use DatabaseTransactions;

    private const PASSWORD = 'correct-horse-battery';

    private User $user;

    /** توکنِ واقعی به‌جای `Sanctum::actingAs` — تا مسیرهای عمومیِ ورود واقعاً مهمان باشند. */
    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create(['password' => Hash::make(self::PASSWORD)]);
        $this->token = $this->user->createToken('test')->plainTextToken;
        $this->withoutMiddleware(ThrottleRequests::class);
    }

    private function asUser(): static
    {
        return $this->withToken($this->token);
    }

    /**
     * یک درخواستِ احرازشده‌ی قبلی در همین پردازه، گاردِ پیش‌فرض را روی `sanctum`
     * می‌گذارد و کاربر را روی گارد نگه می‌دارد — چیزی که بینِ دو درخواستِ واقعی
     * هرگز رخ نمی‌دهد. مسیرهای ورود باید مهمانِ واقعی باشند.
     */
    private function asGuest(): static
    {
        $this->app['auth']->forgetGuards();
        $this->app['auth']->shouldUse('web');

        return $this->flushHeaders();
    }

    /**
     * setup → confirm با کدِ محاسبه‌شده از راز؛ کدهای بازیابی را برمی‌گرداند.
     *
     * confirm با کدِ گامِ **قبل** (داخلِ پنجره‌ی ±۱) انجام می‌شود تا `last_used_step`
     * یک گام عقب بماند و کدِ «الان» که تست‌ها برای ورود می‌سازند تازه باشد — وگرنه
     * گاردِ ضدِ تکرار (RFC 6238 §5.2) همان کدِ confirm را در ورود، درست، رد می‌کند.
     */
    private function enable(): array
    {
        $secret = $this->asUser()->postJson('/api/profile/2fa/setup')->assertOk()->json('data.secret');
        $codes = $this->asUser()->postJson('/api/profile/2fa/confirm', ['code' => Totp::at($secret, time() - Totp::PERIOD)])
            ->assertOk()
            ->json('data.recovery_codes');

        return ['secret' => $secret, 'codes' => $codes];
    }

    private function webTokens(): int
    {
        return $this->user->tokens()->where('name', 'web')->count();
    }

    private function passwordLogin(): TestResponse
    {
        return $this->asGuest()->postJson('/api/authenticate/password/check', [
            'username' => $this->user->mobile,
            'password' => self::PASSWORD,
            'device_name' => 'web',
        ]);
    }

    #[Test]
    public function setup_then_confirm_enables_and_hands_out_ten_recovery_codes_once(): void
    {
        $this->asUser();

        $this->getJson('/api/profile/2fa')->assertOk()->assertJsonPath('data.enabled', false);

        $setup = $this->postJson('/api/profile/2fa/setup')->assertOk();
        $secret = $setup->json('data.secret');
        $this->assertMatchesRegularExpression('/^[A-Z2-7]{32}$/', $secret);
        $this->assertSame(
            Totp::otpauthUri($secret, $this->user->mobile, 'EliteZone'),
            $setup->json('data.otpauth_uri')
        );
        $this->assertStringContainsString('EliteZone%3A'.$this->user->mobile, $setup->json('data.otpauth_uri'));

        // تا confirm، در ورود بی‌اثر است.
        $this->assertFalse($this->user->fresh()->hasTwoFactorEnabled());

        $this->postJson('/api/profile/2fa/confirm', ['code' => '000000'])->assertStatus(422);

        $confirm = $this->postJson('/api/profile/2fa/confirm', ['code' => Totp::at($secret, time())])->assertOk();
        $codes = $confirm->json('data.recovery_codes');
        $this->assertCount(10, $codes);
        foreach ($codes as $code) {
            $this->assertMatchesRegularExpression('/^[A-Z2-9]{5}-[A-Z2-9]{5}$/', $code);
        }

        $this->assertTrue($this->user->fresh()->hasTwoFactorEnabled());
        $this->getJson('/api/profile/2fa')->assertOk()
            ->assertJsonPath('data.enabled', true)
            ->assertJsonPath('data.recovery_codes_left', 10);

        // دوباره setup/confirm روی حسابِ فعال: ۴۰۹، و راز عوض نمی‌شود.
        $this->postJson('/api/profile/2fa/setup')->assertStatus(409);
        $this->postJson('/api/profile/2fa/confirm', ['code' => Totp::at($secret, time())])->assertStatus(409);
        $this->assertSame($secret, $this->user->twoFactor->fresh()->secret);

        // راز و هش‌ها رمزشده/هش‌شده در دیتابیس‌اند، نه متنِ خام.
        $raw = DB::table(UserTwoFactor::TABLE)->where('user_id', $this->user->id)->first();
        $this->assertNotSame($secret, $raw->secret);
        $this->assertStringNotContainsString($codes[0], (string) $raw->recovery_codes);
    }

    #[Test]
    public function password_login_returns_a_challenge_instead_of_a_token(): void
    {
        ['secret' => $secret] = $this->enable();

        $response = $this->passwordLogin()->assertOk()
            ->assertJsonPath('data.requires_2fa', true)
            ->assertJsonMissingPath('data.token')
            ->assertJsonMissingPath('data.user');

        $this->assertSame(0, $this->webTokens(), 'قبل از مرحله‌ی دوم نباید توکنی صادر شود.');

        $challenge = $response->json('data.challenge');
        $this->assertSame(48, strlen($challenge));

        $login = $this->postJson('/api/login/2fa', [
            'challenge' => $challenge,
            'code' => Totp::at($secret, time()),
            'device_name' => 'web',
        ])->assertOk();

        $this->assertNotEmpty($login->json('data.token'));
        $this->assertSame($this->user->id, $login->json('data.user.id'));
        $this->assertSame(1, $this->webTokens());

        // چالش یک‌بارمصرف است.
        $this->postJson('/api/login/2fa', ['challenge' => $challenge, 'code' => Totp::at($secret, time())])
            ->assertStatus(410);
    }

    #[Test]
    public function otp_login_is_gated_the_same_way(): void
    {
        $this->enable();

        $this->user->forceFill([
            User::SECRET => OtpSecret::hash('123456'),
            User::SECRET_EXPIRES_AT => now()->addMinutes(2),
        ])->save();

        $this->asGuest()->postJson('/api/authenticate/verify-code', [
            'username' => $this->user->mobile,
            'code' => '123456',
        ])->assertOk()->assertJsonPath('data.requires_2fa', true)->assertJsonMissingPath('data.token');

        $this->assertSame(0, $this->webTokens());
    }

    #[Test]
    public function a_totp_code_cannot_be_replayed_inside_its_window(): void
    {
        ['secret' => $secret] = $this->enable();
        $code = Totp::at($secret, time());

        $first = $this->passwordLogin()->json('data.challenge');
        $this->postJson('/api/login/2fa', ['challenge' => $first, 'code' => $code])->assertOk();

        $second = $this->passwordLogin()->json('data.challenge');
        $this->postJson('/api/login/2fa', ['challenge' => $second, 'code' => $code])->assertStatus(422);
    }

    #[Test]
    public function a_recovery_code_logs_in_exactly_once(): void
    {
        ['codes' => $codes] = $this->enable();

        $challenge = $this->passwordLogin()->json('data.challenge');
        // حروفِ کوچک و بدونِ خط‌تیره هم پذیرفته می‌شود — کاربر از روی کاغذ تایپ می‌کند.
        $this->postJson('/api/login/2fa', [
            'challenge' => $challenge,
            'recovery_code' => strtolower(str_replace('-', '', $codes[3])),
        ])->assertOk()->assertJsonPath('data.user.id', $this->user->id);

        $this->assertCount(9, $this->user->twoFactor->fresh()->recovery_codes);

        $challenge = $this->passwordLogin()->json('data.challenge');
        $this->postJson('/api/login/2fa', ['challenge' => $challenge, 'recovery_code' => $codes[3]])
            ->assertStatus(422);
        $this->postJson('/api/login/2fa', ['challenge' => $challenge, 'recovery_code' => $codes[4]])
            ->assertOk();
    }

    #[Test]
    public function five_wrong_codes_burn_the_challenge(): void
    {
        ['secret' => $secret] = $this->enable();
        $challenge = $this->passwordLogin()->json('data.challenge');

        for ($i = 0; $i < 4; $i++) {
            $this->postJson('/api/login/2fa', ['challenge' => $challenge, 'code' => '000000'])->assertStatus(422);
        }
        $this->postJson('/api/login/2fa', ['challenge' => $challenge, 'code' => '000000'])->assertStatus(429);

        // حتی کدِ درست هم دیگر روی این چالش کار نمی‌کند — مرحله‌ی اول باید تکرار شود.
        $this->postJson('/api/login/2fa', ['challenge' => $challenge, 'code' => Totp::at($secret, time())])
            ->assertStatus(410);
        $this->assertSame(0, $this->webTokens());
    }

    #[Test]
    public function the_second_step_and_the_profile_verifiers_are_rate_limited(): void
    {
        foreach (['api/login/2fa', 'api/profile/2fa/confirm', 'api/profile/2fa/disable'] as $uri) {
            $route = collect(Route::getRoutes()->getRoutes())->first(fn ($r) => $r->uri() === $uri);
            $this->assertNotNull($route, $uri);
            $this->assertContains('throttle:otp-verify', $route->gatherMiddleware(), $uri);
        }
    }

    #[Test]
    public function a_bogus_or_expired_challenge_is_refused_and_validation_is_persian(): void
    {
        $this->postJson('/api/login/2fa', ['challenge' => str_repeat('x', 48), 'code' => '123456'])
            ->assertStatus(410);

        $this->postJson('/api/login/2fa', [])->assertStatus(422)
            ->assertJsonValidationErrors(['challenge', 'code', 'recovery_code']);
    }

    #[Test]
    public function disabling_needs_a_valid_code_and_restores_plain_login(): void
    {
        ['secret' => $secret, 'codes' => $codes] = $this->enable();

        $this->asUser()->postJson('/api/profile/2fa/disable', ['code' => '000000'])->assertStatus(422);
        $this->assertTrue($this->user->fresh()->hasTwoFactorEnabled());

        $this->asUser()->postJson('/api/profile/2fa/disable', ['code' => $codes[0]])->assertOk()
            ->assertJsonPath('data.enabled', false);
        $this->assertNull($this->user->fresh()->twoFactor);

        $this->asUser()->postJson('/api/profile/2fa/disable', ['code' => Totp::at($secret, time())])->assertStatus(409);

        $this->passwordLogin()->assertOk()->assertJsonMissingPath('data.requires_2fa');
        $this->assertNotEmpty($this->passwordLogin()->json('data.token'));
    }

    #[Test]
    public function a_disabled_account_cannot_finish_the_second_step(): void
    {
        ['secret' => $secret] = $this->enable();
        $challenge = $this->passwordLogin()->json('data.challenge');

        $this->user->forceFill([User::IS_ACTIVE => false])->save();

        $this->postJson('/api/login/2fa', ['challenge' => $challenge, 'code' => Totp::at($secret, time())])
            ->assertStatus(410);
        $this->assertSame(0, $this->webTokens());
    }
}
