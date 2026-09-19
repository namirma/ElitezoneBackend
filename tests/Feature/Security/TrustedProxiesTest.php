<?php

namespace Tests\Feature\Security;

use App\Models\Role;
use App\Models\User;
use Illuminate\Container\Container;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\GrantsAdminPermissions;
use Tests\TestCase;

/**
 * پشتِ Cloudflare، `request()->ip()` IPِ واقعیِ کلاینت است — و فقط پشتِ Cloudflare.
 *
 * بدونِ `trustProxies` آدرسِ لبه برمی‌گشت: همه‌ی کاربرانِ پشتِ یک لبه یک سطلِ throttle
 * داشتند و allowlistِ IPِ ادمین هرگز IPِ واقعی را نمی‌دید. از آن طرف، اعتماد به همه
 * (`'*'`) هم غلط است چون origin مستقیم در دسترس است و X-Forwarded-For جعل‌پذیر.
 */
class TrustedProxiesTest extends TestCase
{
    use DatabaseTransactions;
    use GrantsAdminPermissions;

    private const CF_EDGE_V4 = '172.68.10.20';   // داخلِ 172.64.0.0/13

    private const CF_EDGE_V6 = '2606:4700::6810:1';

    private const NOT_CLOUDFLARE = '198.51.100.7';

    private const CLIENT = '203.0.113.9';

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        Queue::fake();

        $this->app['router']->get('/_test/client-ip', fn (Request $request) => ['ip' => $request->ip()]);
    }

    #[Test]
    public function a_cloudflare_edge_forwards_the_client_ip(): void
    {
        $this->assertSame(self::CLIENT, $this->ipSeenFrom(self::CF_EDGE_V4, self::CLIENT));
        $this->assertSame(self::CLIENT, $this->ipSeenFrom(self::CF_EDGE_V6, self::CLIENT));
    }

    #[Test]
    public function a_forged_header_from_outside_cloudflare_is_ignored(): void
    {
        $this->assertSame(self::NOT_CLOUDFLARE, $this->ipSeenFrom(self::NOT_CLOUDFLARE, self::CLIENT));
    }

    #[Test]
    public function a_spoofed_entry_the_client_prepends_through_cloudflare_is_ignored(): void
    {
        // Cloudflare IPِ واقعی را به **ته** هدرِ ارسالیِ کلاینت اضافه می‌کند.
        $this->assertSame(self::CLIENT, $this->ipSeenFrom(self::CF_EDGE_V4, '6.6.6.6, '.self::CLIENT));
    }

    #[Test]
    public function clients_behind_one_cloudflare_edge_get_their_own_sms_bucket(): void
    {
        $statuses = [];
        foreach ([1, 2, 3] as $i) {
            $statuses[] = $this->checkUser(self::CF_EDGE_V4, "5.160.0.{$i}", "0912555001{$i}");
        }

        $this->assertSame([200, 200, 200], $statuses, 'three people behind one edge must not share the 2/min bucket');
    }

    #[Test]
    public function the_sms_limiter_still_bites_the_real_client(): void
    {
        $statuses = [];
        foreach ([1, 2, 3] as $i) {
            $statuses[] = $this->checkUser(self::CF_EDGE_V4, '5.160.0.1', "0912555002{$i}");
        }

        $this->assertSame([200, 200, 429], $statuses);
    }

    #[Test]
    public function the_admin_ip_allowlist_sees_the_real_ip_behind_cloudflare(): void
    {
        config(['security.admin_ip_whitelist' => ['5.160.0.1']]);
        $admin = User::factory()->create();
        $admin->addRole(Role::firstOrCreate(['name' => 'admin'], ['display_name' => 'Admin']));
        Sanctum::actingAs($this->grantAllAdminPermissions($admin));

        $this->withServerVariables(['REMOTE_ADDR' => self::CF_EDGE_V4])
            ->withHeaders(['X-Forwarded-For' => '5.160.0.1'])
            ->getJson('/api/admin/users')
            ->assertOk();

        $this->withServerVariables(['REMOTE_ADDR' => self::CF_EDGE_V4])
            ->withHeaders(['X-Forwarded-For' => '5.160.0.2'])
            ->getJson('/api/admin/users')
            ->assertForbidden();
    }

    /**
     * `public/index.php` کرنلِ HTTP را **پیش از** bootstrap resolve می‌کند، پس closureِ
     * `withMiddleware` بدونِ config اجرا می‌شود. `config()` آن‌جا روی وب خطا می‌داد در
     * حالی که بقیه‌ی تست‌ها (که اول bootstrap می‌کنند) سبز می‌ماندند. همین مسیر را بازسازی
     * می‌کند.
     */
    #[Test]
    public function the_middleware_closure_runs_without_config_as_on_the_web(): void
    {
        $fresh = require base_path('bootstrap/app.php');

        try {
            $this->assertFalse($fresh->bound('config'));
            $fresh->make(Kernel::class); // اگر closure به config دست بزند این‌جا پرتاب می‌کند
        } finally {
            Container::setInstance($this->app);
        }

        $this->assertFalse($fresh->bound('config'));
    }

    private function ipSeenFrom(string $remoteAddr, string $forwardedFor): ?string
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $remoteAddr])
            ->withHeaders(['X-Forwarded-For' => $forwardedFor])
            ->getJson('/_test/client-ip')
            ->json('ip');
    }

    private function checkUser(string $remoteAddr, string $forwardedFor, string $mobile): int
    {
        User::factory()->create([User::MOBILE => $mobile]);

        return $this->withServerVariables(['REMOTE_ADDR' => $remoteAddr])
            ->withHeaders(['X-Forwarded-For' => $forwardedFor])
            ->postJson('/api/authenticate/check-user', ['username' => $mobile])
            ->status();
    }
}
