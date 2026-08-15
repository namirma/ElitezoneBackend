<?php

namespace Tests\Feature\Navigation;

use App\Actions\Profile\ProfileNavigationAction;
use App\Models\Order;
use App\Services\DashboardAlertsService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use Tests\TestCase;

/**
 * هر آدرسی که بک‌اند تولید می‌کند باید یک روتِ واقعیِ SPA باشد.
 *
 * چرا این تست وجود دارد: بک‌اند در سه جا آدرسِ صفحه می‌سازد (جست‌وجوی پنل،
 * هشدارهای داشبورد، دکمه‌های تایم‌لاین) و این آدرس‌ها مستقیم به `navigate()`
 * می‌روند. یک آدرسِ نزدیک-اما-نادرست یعنی کاربر روی لینکِ خودِ ما به ۴۰۴
 * می‌رسد — که برای `/products` (کاتالوگ در SPA `/rebar` است) واقعاً اتفاق
 * افتاده بود.
 *
 * فرانت تا امروز یک جدولِ ترجمه‌ی ۶۰ ردیفی نگه می‌داشت تا این‌ها را قبل از
 * ناوبری تصحیح کند، ولی آن جدول فقط روی دو مصرف‌کننده اجرا می‌شد؛ هر
 * مصرف‌کننده‌ی تازه دوباره ۴۰۴ می‌گرفت. با سبز ماندنِ این تست، آن جدول حذف
 * می‌شود — پس **این تست تنها چیزی است که جلوی برگشتِ مشکل را می‌گیرد.**
 *
 * منبع حقیقت: روت‌های React Router در `EliteZone_FrontEnd_Phase2/src/App.jsx`.
 */
class EmittedUrlsMatchSpaRoutesTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * الگوی هر روتِ واقعیِ SPA.
     *
     * تله‌های تکرارشونده که این الگوها می‌گیرند:
     *   - جداکننده `_` است نه `-` (`my_items`، `account_information`)
     *   - `finance` نه `financial`؛ `support` نه `tickets`
     *   - تب‌ها تودرتواند: `/profile/{section}/{tab}`
     *   - جزئیات محصول مفرد است و اول id: `/product/{id}/{slug?}`
     */
    private const SPA_ROUTES = [
        '#^/$#',
        '#^/rebar$#',
        '#^/product/\d+(/[^/]*)?$#',
        '#^/faq$#',
        '#^/p/[^/]+$#',
        '#^/auth$#',
        '#^/order$#',
        '#^/role$#',
        '#^/profile$#',
        '#^/profile/orders(/(self|others)(/(current|delivered|cancelled))?)?$#',
        '#^/profile/finance(/(wallet|accounts_number))?$#',
        '#^/profile/my_items(/(saved|notify_me|badges|surveys))?$#',
        '#^/profile/account_information(/(personal|legal))?$#',
        '#^/profile/professional(/(education|job))?$#',
        '#^/profile/(employers|support|messages|addresses|account_settings|agreements|others|supplier_info)$#',
        '#^/admin(/(suppliers(/\d+)?|orders|tickets(/\d+)?|financial))?$#',
    ];

    private function assertIsSpaRoute(string $url, string $source): void
    {
        foreach (self::SPA_ROUTES as $pattern) {
            if (preg_match($pattern, $url) === 1) {
                $this->addToAssertionCount(1);

                return;
            }
        }

        $this->fail("«{$url}» از {$source} با هیچ روتِ SPA مطابقت ندارد.");
    }

    /**
     * سفارشِ لغوشده‌ای که **بیعانه پرداخت کرده**.
     *
     * دکمه‌ی «خرید مجدد» فقط روی مرحله‌ی «رزرو» و فقط در حالت `cancelled`
     * ساخته می‌شود، و آن مرحله خودش تنها وقتی وجود دارد که
     * `payment_to_reserve > 0` باشد (بند ۱۱.۱). بدون بیعانه، تایم‌لاین اصلاً
     * لینکِ SPAیی تولید نمی‌کند و تست بی‌صدا هیچ‌چیز را نمی‌سنجد.
     */
    private function cancelledOrderWithDeposit(): Order
    {
        $order = Order::factory()->create([Order::STATUS => Order::STATUS_CANCELLED]);
        $order->forceFill([Order::PAYMENT_TO_RESERVE => 20_000_000])->save();

        return $order->fresh();
    }

    #[Test]
    public function every_profile_navigation_url_is_a_real_spa_route(): void
    {
        $items = (new ReflectionClass(ProfileNavigationAction::class))
            ->getProperty('quickAccessItems')
            ->getValue(new ProfileNavigationAction);

        $this->assertNotEmpty($items);

        foreach ($items as $item) {
            $this->assertIsSpaRoute($item['url'], 'ProfileNavigationAction');
        }
    }

    #[Test]
    public function every_dashboard_alert_url_is_a_real_spa_route(): void
    {
        // آرایه‌ی `makeAlert` آدرس را به‌صورت رشته‌ی خام می‌سازد، پس به‌جای
        // اجرای سرویس (که به دادهٔ زیاد نیاز دارد) خودِ سورس جارو می‌شود.
        $source = file_get_contents((new ReflectionClass(DashboardAlertsService::class))->getFileName());

        preg_match_all("#'(/(?:profile|product|rebar|admin|order|role|faq|p)[^']*)'#", $source, $matches);

        $this->assertNotEmpty($matches[1], 'هیچ آدرسی در DashboardAlertsService پیدا نشد — الگوی جست‌وجو کهنه شده؟');

        foreach (array_unique($matches[1]) as $url) {
            // آدرس‌های تعاملی (`'/product/'.$id.'/'.$slug`) به‌صورت تکه‌ای در
            // سورس‌اند؛ تکه‌ی ثابتشان با یک id/slug نمونه کامل می‌شود.
            $probe = rtrim($url, '/');
            $probe = str_ends_with($probe, '/product') ? '/product/1/sample' : $probe;

            $this->assertIsSpaRoute($probe, 'DashboardAlertsService');
        }
    }

    #[Test]
    public function every_order_timeline_link_is_a_real_spa_route(): void
    {
        $order = $this->cancelledOrderWithDeposit();

        // هر سه تایم‌لاین، در وضعیت لغو (که دکمه‌ی «خرید مجدد» را می‌سازد —
        // همان لینکی که به `/products`ِ ناموجود اشاره می‌کرد).
        $progressSets = [
            'dashboard_progress' => $order->dashboard_progress,
            'supplier_progress' => $order->supplier_progress,
            'elite_yar_progress' => $order->elite_yar_progress,
        ];

        foreach ($progressSets as $name => $progress) {
            foreach ($progress['steps'] as $step) {
                foreach ($step['actions'] as $action) {
                    // لینک‌های `/api/...` دانلودِ سند هستند و روتِ SPA نیستند.
                    if (str_starts_with($action['link'], '/api/')) {
                        continue;
                    }

                    $this->assertIsSpaRoute($action['link'], "HasOrderProgress::{$name}");
                }
            }
        }
    }

    #[Test]
    public function the_catalogue_link_points_at_rebar_not_products(): void
    {
        // رگرسیونِ نقطه‌ای: این همان ۴۰۴ زنده‌ای بود که کاربر می‌دید، چون فرانت
        // لینک‌های تایم‌لاین را بدون عبور از جدولِ ترجمه‌اش navigate می‌کند.
        $order = $this->cancelledOrderWithDeposit();

        $links = collect($order->dashboard_progress['steps'])
            ->flatMap(fn ($step) => array_column($step['actions'], 'link'));

        $this->assertContains('/rebar', $links->all());
        $this->assertNotContains('/products', $links->all());
    }
}
