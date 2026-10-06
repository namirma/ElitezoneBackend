<?php

declare(strict_types=1);

namespace Tests\Feature\Profile;

use App\Services\Saze90BadgeService;
use App\Services\Saze90Unavailable;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * مدیریت، دورِ پنجم، پرسشِ ۸ (۱۴۰۵/۰۷/۱۴): API جدیدِ سازه ۹۰ لایسنس‌های هر موبایل را با نوعِ نشان و
 * تاریخِ اعتبارِ SUM می‌دهد. یک لایسنس ⇒ همان؛ چند لایسنس ⇒ طلایی > نقره‌ای > برنزی > دورترین SUM.
 * قرارداد: docs/specs/SAZE90_LICENCE_API_FOR_SAJAD.md (ریشه‌ی ورک‌اسپیس).
 */
class Saze90LicenceSelectionTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        config([
            'services.saze90.enabled' => true,
            'services.saze90.base_url' => 'https://www.saze90.com',
            'services.saze90.licence_api.enabled' => true,
            'services.saze90.licence_api.path' => '/api/elitezone/licences',
            'services.saze90.licence_api.token' => 'test-token',
        ]);
    }

    #[Test]
    public function the_highest_badge_wins_and_the_farthest_sum_breaks_a_tie(): void
    {
        $pick = fn (array $licences) => Saze90BadgeService::selectLicence($licences)['licence_no'] ?? null;

        $this->assertNull(Saze90BadgeService::selectLicence([]));
        $this->assertSame('A', $pick([['licence_no' => 'A', 'badge' => 'bronze', 'sum_expires_at' => '2025-01-01']]));
        $this->assertSame('G', $pick([
            ['licence_no' => 'B', 'badge' => 'bronze', 'sum_expires_at' => '2030-01-01'],
            ['licence_no' => 'G', 'badge' => 'GOLD', 'sum_expires_at' => '2024-01-01'],
            ['licence_no' => 'S', 'badge' => 'silver', 'sum_expires_at' => '2031-01-01'],
        ]));
        $this->assertSame('S2', $pick([
            ['licence_no' => 'S1', 'badge' => 'silver', 'sum_expires_at' => '2027-03-01'],
            ['licence_no' => 'S2', 'badge' => 'silver', 'sum_expires_at' => '2028-02-01'],
        ]));
        $this->assertSame('N2', $pick([
            ['licence_no' => 'N1', 'badge' => null, 'sum_expires_at' => '2026-01-01'],
            ['licence_no' => 'N2', 'badge' => null, 'sum_expires_at' => '2029-01-01'],
        ]));
    }

    #[Test]
    public function the_service_returns_the_chosen_licences_badge_with_the_bearer_token(): void
    {
        Http::fake(['*api/elitezone/licences*' => Http::response(['mobile' => '09120000001', 'licences' => [
            ['licence_no' => 'L-1', 'badge' => 'bronze', 'sum_expires_at' => '2027-01-01'],
            ['licence_no' => 'L-2', 'badge' => 'silver', 'sum_expires_at' => '2026-01-01'],
        ]], 200)]);

        $this->assertSame('silver', app(Saze90BadgeService::class)->getCertificate('09120000001'));

        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer test-token')
            && str_contains($request->url(), 'mobile=09120000001'));
    }

    #[Test]
    public function a_licence_without_a_badge_is_still_a_member_and_no_licence_is_a_confirmed_no(): void
    {
        Http::fake([
            '*mobile=09120000002*' => Http::response(['licences' => [['licence_no' => 'X', 'badge' => null, 'sum_expires_at' => null]]], 200),
            '*mobile=09120000003*' => Http::response(['licences' => []], 200),
            '*mobile=09120000004*' => Http::response(['message' => 'not found'], 404),
        ]);
        $service = app(Saze90BadgeService::class);

        $this->assertSame('saze90', $service->getCertificate('09120000002'));
        $this->assertNull($service->getCertificate('09120000003'));
        $this->assertNull($service->getCertificate('09120000004'));
    }

    #[Test]
    public function a_malformed_answer_is_an_outage_not_a_no(): void
    {
        Http::fake(['*' => Http::response(['unexpected' => true], 200)]);

        // پیام هم سنجیده می‌شود: هر خطایی (حتی کلاسِ ناموجود) به Saze90Unavailable تبدیل می‌شود،
        // پس فقط نوعِ استثنا نشان نمی‌دهد که شکلِ پاسخ تشخیص داده شده است.
        $this->expectException(Saze90Unavailable::class);
        $this->expectExceptionMessage('bad shape');
        app(Saze90BadgeService::class)->getCertificate('09120000005');
    }
}
