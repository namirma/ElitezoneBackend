<?php

namespace Tests\Feature\Security;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * `trustHosts()` در `bootstrap/app.php` واقعاً Host را می‌سنجد — اجرا-به-اجرا، نه
 * «پشتِ Cloudflare مهار شده».
 *
 * دو بن‌بستی که تا امروز مانعِ تأیید بود، هر دو دور زده می‌شوند:
 *  - `TrustHosts::shouldSpecifyTrustedHosts()` در `local` و در `testing`
 *    (`runningUnitTests()`) کاری نمی‌کند → محیط داخلِ همین تست به `production`
 *    برگردانده می‌شود (`detectEnvironment`)، پیش از آن‌که درخواست ساخته شود.
 *  - از راهِ URLِ استیجینگ Cloudflare هدرِ جعلی را در لبه رد می‌کند و به PHP
 *    نمی‌رسد → این‌جا درخواست مستقیم به Kernel می‌رود.
 *
 * سازوکارِ رد: میدل‌ور `Request::setTrustedHosts()` را روی الگوی
 * `^(.+\.)?<host of APP_URL>$` می‌گذارد؛ اولین `getHost()` (در `HandleCors`،
 * روی هر درخواست) برای میزبانِ نامنطبق `SuspiciousOperationException` می‌اندازد
 * که `Handler::prepareException` به ۴۰۰ (`BadRequestHttpException`) نگاشت می‌کند.
 * میزبانِ درخواست از خودِ URL می‌آید — `MakesHttpRequests` هدرِ Host را با میزبانِ
 * URL بازنویسی می‌کند، پس هدر دادن بی‌اثر است و URLِ کامل لازم است.
 */
class TrustedHostsTest extends TestCase
{
    use DatabaseTransactions;

    private const PROBE = '/api/settings/public';

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.url' => 'https://api.elitezone.example']);
        // بعد از این خط `runningUnitTests()` false است و میدل‌ور فعال می‌شود.
        $this->app->detectEnvironment(fn () => 'production');
    }

    protected function tearDown(): void
    {
        // وضعیتِ ایستای Symfony است و بینِ تست‌های همین پردازه می‌ماند — بدونِ این
        // هر تستِ بعدی روی `localhost` ۴۰۰ می‌گرفت.
        Request::setTrustedHosts([]);

        parent::tearDown();
    }

    #[Test]
    public function a_forged_host_header_is_rejected(): void
    {
        $this->getJson('https://evil.example'.self::PROBE)->assertStatus(400);
    }

    #[Test]
    public function the_configured_host_and_its_subdomains_pass(): void
    {
        $this->getJson('https://api.elitezone.example'.self::PROBE)->assertOk();
        $this->getJson('https://staging.api.elitezone.example'.self::PROBE)->assertOk();
    }

    #[Test]
    public function a_host_that_merely_ends_with_the_domain_is_still_rejected(): void
    {
        // `evilapi.elitezone.example` نه خودِ دامنه است نه زیردامنه‌اش — الگو نقطه می‌خواهد.
        $this->getJson('https://evilapi.elitezone.example'.self::PROBE)->assertStatus(400);
    }

    #[Test]
    public function the_guard_is_off_in_local_so_the_dev_servers_keep_working(): void
    {
        Request::setTrustedHosts([]);
        $this->app->detectEnvironment(fn () => 'local');

        $this->getJson('https://evil.example'.self::PROBE)->assertOk();
    }
}
