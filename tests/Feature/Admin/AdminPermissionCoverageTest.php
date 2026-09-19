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
 *  ۱. `POST /admin/{order}/weighbridge` — aliasِ قدیمیِ باسکول. نسخه‌ی استاندارد
 *     (`/admin/orders/{order}/weighbridge`) پشتِ manage-orders بود ولی این نه، پس
 *     ادمینی با فقط manage-content می‌توانست سنگین‌ترین عملیات مالی سیستم را اجرا
 *     کند (بازمحاسبه‌ی POO/PTS، عودت خریدار، بدهی تأمین‌کننده، هولد مالیات).
 *     آن کنترلر هیچ چک داخلی نداشت. هر دو روت در بازطراحی حذف شدند (بند ۱۶).
 *  ۲. روت‌های واریانت محصول — بدون manage-catalog، برخلافِ همه‌ی گروه‌های هم‌ردیف.
 *  ۳. جستجو و lookupهای کاربر — همان PII و سابقه‌ی مالیِ گروهِ گِیت‌شده را
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
        'api/admin/debug/test-cache' => 'فقط خارج از production ثبت می‌شود (شرطِ ! app()->environment("production") در routes/api/admin.php).',
        'api/admin/debug/kavir-products' => 'فقط خارج از production ثبت می‌شود (همان شرط).',
    ];

    /** @return list<string> */
    private function ungatedAdminRoutes(): array
    {
        $offenders = [];

        foreach (Route::getRoutes() as $route) {
            $uri = $route->uri();

            // ‏`api/data` هم جارو می‌شود. تا امروز فقط `/admin` و `/v1/podium`
            // دیده می‌شدند، و دو روتِ آنالیتیکس که بیرونِ هر دو پیشوند بودند
            // ماه‌ها بدون `admin.permission` ماندند — همان داده‌ی درآمد و CLV که
            // `/admin/dashboard` پشتِ `manage-financial` قفلش کرده بود. شکافی
            // که تستِ پوششْ ساختاراً نمی‌توانست ببیند.
            if (! str_starts_with($uri, 'api/admin')
                && ! str_starts_with($uri, 'api/v1/podium')
                && ! str_starts_with($uri, 'api/data')) {
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

    /**
     * باسکول دیگر هیچ ورودیِ ادمینی ندارد (فاز ۷، بند ۱۶).
     *
     * دو روت اینجا مرده‌اند و هیچ‌کدام نباید برگردند:
     *
     *   • aliasِ بی‌مجوزِ ریشه‌ی /admin — بدون مجوز بود و سنگین‌ترین عملیاتِ مالیِ
     *     سیستم را برای هر ادمینی باز می‌گذاشت.
     *   • نسخه‌ی استانداردِ پشتِ manage-orders — تا فاز ۷ **پلِ موقت** بود، چون
     *     پس از حذفِ مسیرِ بارنامه در فاز ۰ تنها نویسنده‌ی وضعیت `shipped` مانده
     *     بود. حالا جایگزینش ساخته شده:
     *     `POST /profile/orders/{order}/shipments/{shipment}/waybill` — وزن را
     *     همان تأمین‌کننده‌ای ثبت می‌کند که بار را تحویل راننده می‌دهد.
     */
    #[Test]
    public function the_weighbridge_has_no_admin_entry_point_left(): void
    {
        $uris = array_map(fn ($route) => $route->uri(), iterator_to_array(Route::getRoutes()));

        $this->assertNotContains('api/admin/{order}/weighbridge', $uris);
        $this->assertNotContains('api/admin/orders/{order}/weighbridge', $uris);
    }
}
