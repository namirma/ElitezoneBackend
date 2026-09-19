<?php

namespace Tests\Feature\Navigation;

use App\Actions\Profile\ProfileNavigationAction;
use App\Models\Order;
use App\Services\Cart\CartPaymentLinkService;
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
        // لینکِ پیامکیِ «خرید برای دیگری» (`CartPaymentLinkService::linkFor`).
        '#^/checkout/pay-for/\d+$#',
        // جزئیاتِ یک سفارش — روتِ router نیست: طبق گزارشِ تیم فرانت،
        // `useOrderActionHandler` سه دسته لینک را پیش از navigate رهگیری می‌کند
        // (دانلودِ سند، اورلیِ مودیان، پنلِ محموله‌ها) و این یکی همان دسته‌ی سوم
        // است — «بارنامه و راننده» و «تأیید تحویل» هر دو پنلِ محموله را باز
        // می‌کنند، و تأیید تحویل در کامیتِ 7ea24ad آن‌ها ساخته و تأیید شده.
        //
        // اگر روزی آن رهگیری برداشته شود این سه لینک به همان بن‌بستِ
        // `/checkout/payment/{id}` می‌رسند، پس اینجا صریح ثبت می‌شود نه ضمنی.
        '#^/profile/orders/\d+$#',
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
    #[Test]
    public function the_buy_for_others_sms_link_points_at_a_real_spa_route(): void
    {
        // ⚠️ داک‌بلاکِ `CartPaymentLinkService::linkFor()` ادعا می‌کرد همین تست
        // پوششش می‌دهد — ولی این کلاس جزوِ منابعِ سوئیپ نبود و `SPA_ROUTES` هم
        // الگویی برای `checkout/pay-for/{id}` نداشت. یعنی لینکی که واقعاً
        // **پیامک** می‌شد به روتی می‌رفت که وجود نداشت، و ادعای پوشش بی‌صدا
        // غلط بود.
        $source = file_get_contents(
            (new ReflectionClass(CartPaymentLinkService::class))->getFileName()
        );

        preg_match_all("#'(/[a-z0-9\-/_]*)'#i", $source, $matches);

        $this->assertNotEmpty($matches[1], 'هیچ مسیری در CartPaymentLinkService پیدا نشد — الگو کهنه شده؟');

        $checked = 0;

        foreach ($matches[1] as $path) {
            // `'/'` تنها آرگومانِ `rtrim` است، نه یک مسیر.
            if (trim($path, '/') === '') {
                continue;
            }

            // بخش‌های پویا با یک شناسه‌ی نمونه پر می‌شوند، مثل بقیه‌ی منابع.
            $this->assertIsSpaRoute(rtrim($path, '/').'/1', 'CartPaymentLinkService');
            $checked++;
        }

        $this->assertGreaterThan(0, $checked, 'هیچ مسیرِ واقعی سنجیده نشد — الگو کهنه شده؟');
    }

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
        // اجرای سرویس (که به داده‌ی زیاد نیاز دارد) خودِ سورس جارو می‌شود.
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
        // ⚠️ چند وضعیت، نه یکی.
        //
        // این تست قبلاً فقط سفارشِ **لغوشده** را می‌گشت. روی آن، مرحله‌ی رزرو
        // حالتِ `cancelled` می‌گیرد نه `current` — و شاخه‌ای که اکشنِ «پرداخت
        // فاکتور» را می‌ساخت `if ($state === 'current')` بود. پس آن لینک هرگز
        // تولید نمی‌شد، تست هرگز نمی‌دیدش، و یک مقصدِ مرده در SPA سبز رد می‌شد.
        // تیم فرانت آن را در مرورگر پیدا کرد، نه اینجا.
        //
        // یک تستِ sweep که روی یک حالت اجرا شود sweep نیست.
        $orders = [
            'cancelled' => $this->cancelledOrderWithDeposit(),
            'reserved' => $this->orderInState(Order::STATUS_RESERVED, [Order::PAYMENT_TO_RESERVE => 20_000_000]),
            'processing' => $this->orderInState(Order::STATUS_PROCESSING),
            'shipped' => $this->orderInState(Order::STATUS_SHIPPED),
            'delivered' => $this->orderInState(Order::STATUS_DELIVERED),
        ];

        $checked = 0;

        foreach ($orders as $stateName => $order) {
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

                        $checked++;
                        $this->assertIsSpaRoute($action['link'], "HasOrderProgress::{$name} ({$stateName})");
                    }
                }
            }
        }

        // بدونِ این، خالی‌شدنِ فیکسچر دوباره تست را بی‌صدا سبز می‌کند.
        $this->assertGreaterThan(0, $checked, 'هیچ لینکِ SPAیی بررسی نشد — فیکسچر پوچ است.');
    }

    /** @param  array<string, mixed>  $snapshot */
    private function orderInState(string $status, array $snapshot = []): Order
    {
        $order = Order::factory()->create([Order::STATUS => $status]);

        if ($snapshot !== []) {
            $order->forceFill($snapshot)->save();
        }

        return $order->fresh();
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
