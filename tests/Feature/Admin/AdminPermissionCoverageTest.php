<?php

namespace Tests\Feature\Admin;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * هر روتِ حساسِ /admin باید علاوه بر role.admin یک admin.permission هم داشته باشد.
 *
 * سه شکافِ واقعی که این تست جلوی برگشتشان را می‌گیرد:
 *
 *  ۱. `POST /admin/{order}/weighbridge` — aliasِ قدیمیِ باسکول. نسخهٔ استاندارد
 *     (`/admin/orders/{order}/weighbridge`) پشتِ manage-orders بود ولی این نه، پس
 *     ادمینی با فقط manage-content می‌توانست سنگین‌ترین عملیات مالی سیستم را اجرا
 *     کند (بازمحاسبهٔ POO/PTS، عودت خریدار، بدهی تأمین‌کننده، هولد مالیات).
 *     OrderWeighbridgeController هیچ چک داخلی ندارد.
 *  ۲. روت‌های واریانت محصول — بدون manage-catalog، برخلافِ همهٔ گروه‌های هم‌ردیف.
 *  ۳. جستجو و lookupهای کاربر — همان PII و سابقهٔ مالیِ گروهِ گِیت‌شده را
 *     بدون دروازه برمی‌گرداندند.
 */
class AdminPermissionCoverageTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * روت‌هایی که عمداً فقط role.admin دارند، به‌همراه دلیل.
     *
     * @var array<string, string>
     */
    private const INTENTIONALLY_UNGATED = [
        'api/admin/dashboard' => 'صفحهٔ خانهٔ پنل — گِیت کردنش ادمینِ محدودشده را از صفحهٔ اول ۴۰۳ می‌کند بدون آنکه چیزی امن‌تر شود؛ داده‌های ریز مالی پشت /admin/financial/* هستند.',
        'api/admin/debug/test-cache' => 'فقط خارج از production ثبت می‌شود (شرطِ ! app()->environment("production") در routes/api/admin.php).',
        'api/admin/debug/kavir-products' => 'فقط خارج از production ثبت می‌شود (همان شرط).',
    ];

    /** @return list<string> */
    private function ungatedAdminRoutes(): array
    {
        $offenders = [];

        foreach (Route::getRoutes() as $route) {
            $uri = $route->uri();

            if (! str_starts_with($uri, 'api/admin') && ! str_starts_with($uri, 'api/v1/podium')) {
                continue;
            }

            if (array_key_exists($uri, self::INTENTIONALLY_UNGATED)) {
                continue;
            }

            $middleware = $route->gatherMiddleware();
            $hasPermission = false;

            foreach ($middleware as $m) {
                // gatherMiddleware() آبشارِ خام را می‌دهد و aliasها را resolve نمی‌کند،
                // پس هم شکل alias و هم نام کلاس باید پوشش داده شود.
                if (is_string($m) && (str_contains($m, 'admin.permission') || str_contains($m, 'EnsureAdminHasPermission'))) {
                    $hasPermission = true;
                    break;
                }
            }

            if (! $hasPermission) {
                $offenders[] = implode('|', $route->methods()).' '.$uri;
            }
        }

        return array_values(array_unique($offenders));
    }

    #[Test]
    public function every_admin_route_is_behind_a_granular_permission(): void
    {
        $offenders = $this->ungatedAdminRoutes();

        $this->assertSame(
            [],
            $offenders,
            "این روت‌های /admin مجوز granular ندارند:\n - ".implode("\n - ", $offenders)
        );
    }

    #[Test]
    public function the_legacy_weighbridge_alias_requires_the_same_permission_as_the_canonical_route(): void
    {
        $permissionOf = function (string $uri): array {
            foreach (Route::getRoutes() as $route) {
                if ($route->uri() === $uri) {
                    return array_values(array_filter(
                        $route->gatherMiddleware(),
                        fn ($m) => is_string($m)
                            && (str_contains($m, 'admin.permission') || str_contains($m, 'EnsureAdminHasPermission'))
                    ));
                }
            }

            return [];
        };

        $canonical = $permissionOf('api/admin/orders/{order}/weighbridge');
        $legacy = $permissionOf('api/admin/{order}/weighbridge');

        $this->assertNotEmpty($canonical, 'روت استاندارد باسکول باید مجوز داشته باشد.');
        $this->assertSame(
            $canonical,
            $legacy,
            'alias قدیمی باسکول باید دقیقاً همان مجوزِ روت استاندارد را داشته باشد.'
        );
    }
}
