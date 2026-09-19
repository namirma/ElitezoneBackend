<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Models\User;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use Laratrust\Models\Role;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use ReflectionMethod;
use Tests\TestCase;

/**
 * هر مسیری که پیامک می‌فرستد، سرویسِ *پولی* صدا می‌زند، یا رازِ کوتاه را مقایسه
 * می‌کند، باید `throttle` داشته باشد.
 *
 * چرا این تست وجود دارد: پنج مسیر با **دقیقاً یک شکل** از پنج دورِ سخت‌سازی جا
 * مانده بودند — `/profile/password` (که کدِ OTP را مصرف می‌کرد و رمز را می‌نوشت،
 * در حالی که سقف روی گامِ فقط-خواندنیِ کنارش بود)، `/profile/password/otp`،
 * `/profile/supplier/reserve-time` (که به **همه‌ی** سبدهای پارک‌شده پیامک می‌دهد)،
 * `/profile/supplier/drivers/{driver}/verify` (پیامک به شماره‌ای که خودِ
 * تأمین‌کننده وارد کرده) و `POST /profile/financial/accounts` (دو استعلامِ پولیِ
 * Podium در هر فراخوانی).
 *
 * هر بار یکی‌شان دستی پیدا شد و دورِ بعد یکی دیگر جا ماند. `AdminPermissionCoverageTest`
 * همین مسئله را برای دسترسیِ ادمین با **جاروی جدولِ روت** حل کرد، نه با نمونه‌گیری؛
 * این تست همان کار را برای سقفِ نرخ می‌کند.
 *
 * روش: بدنه‌ی متدِ کنترلرِ هر روت را از روی فایل می‌خوانیم (نه کلِ کلاس — وگرنه یک
 * متدِ پیامکی، ۱۰ متدِ بی‌ربطِ همان کنترلر را هم علامت‌دار می‌کرد) و دنبالِ نشانه‌ها
 * می‌گردیم. سطحی است و عمداً هم سطحی مانده: یک هیوریستیکِ ساده که کسی نمی‌شکندش،
 * بهتر از تحلیلِ فراخوانیِ کاملی است که با اولین ریفکتور از کار می‌افتد.
 */
class RateLimitCoverageTest extends TestCase
{
    /**
     * نشانه‌های «این متد گران است»: هر کدام یا پول خرج می‌کند، یا به گوشیِ یک
     * آدمِ واقعی پیام می‌دهد، یا یک رازِ brute-force پذیر را مقایسه می‌کند.
     *
     * @var list<string>
     */
    private const EXPENSIVE = [
        'SendSmsJob',        // پیامکِ صف‌شده
        'SendInviteSmsJob',  // پیامکِ دعوت
        'notifyWaitingCarts', // فن‌اوتِ پیامک به همه‌ی سبدهای پارک‌شده
        'OtpSecret::matches', // مقایسه‌ی کدِ ۶ رقمی
        'PodiumServices\\',  // استعلامِ پولی (use-شده با نامِ کامل)
        'InquiryService',    // CardInquiry / ShebaInquiry / CompanyInquiry …
        'CardToIbanService',
    ];

    /**
     * مسیرهایی که عمداً بی‌سقف‌اند، با دلیل.
     *
     * @var array<string, string>
     */
    private const EXEMPT = [
        // درگاهِ بانک خودش تعدادِ فراخوانی را تعیین می‌کند، نه کاربر؛ و یک سقف
        // اینجا یعنی از دست دادنِ callbackِ یک پرداختِ واقعی. این دو مسیر سقفِ
        // خودشان را جداگانه در routes/api/public.php دارند (`throttle:30,1`).
        'api/payment/callback' => 'callback درگاه — سقفِ اختصاصی دارد',
        'api/payment/result' => 'callback درگاه — سقفِ اختصاصی دارد',
    ];

    /** آیا این روت (یا گروهش) هر نوع throttle دارد؟ */
    private function hasThrottle(RoutingRoute $route): bool
    {
        foreach ($route->gatherMiddleware() as $middleware) {
            if (is_string($middleware) && str_starts_with($middleware, 'throttle')) {
                return true;
            }
        }

        return false;
    }

    /** سورسِ بدنه‌ی متدِ کنترلرِ این روت، یا null اگر قابلِ تحلیل نباشد. */
    private function actionSource(RoutingRoute $route): ?string
    {
        $action = $route->getActionName();

        if (! str_contains($action, '@')) {
            return null; // closure یا invokable — خارج از دامنه‌ی این جارو
        }

        [$class, $method] = explode('@', $action, 2);

        if (! class_exists($class) || ! method_exists($class, $method)) {
            return null;
        }

        $reflection = new ReflectionMethod($class, $method);
        $file = (new ReflectionClass($class))->getFileName();

        if ($file === false || $reflection->getStartLine() === false) {
            return null;
        }

        $lines = file($file) ?: [];
        $body = array_slice(
            $lines,
            $reflection->getStartLine() - 1,
            $reflection->getEndLine() - $reflection->getStartLine() + 1
        );

        return implode('', $body);
    }

    #[Test]
    public function every_route_that_sends_an_sms_or_calls_a_paid_service_is_throttled(): void
    {
        $unthrottled = [];
        $checked = 0;

        foreach (Route::getRoutes() as $route) {
            $uri = $route->uri();

            if (! str_starts_with($uri, 'api/') || isset(self::EXEMPT[$uri])) {
                continue;
            }

            $source = $this->actionSource($route);

            if ($source === null) {
                continue;
            }

            $isExpensive = false;
            foreach (self::EXPENSIVE as $marker) {
                if (str_contains($source, $marker)) {
                    $isExpensive = true;
                    break;
                }
            }

            if (! $isExpensive) {
                continue;
            }

            $checked++;

            if (! $this->hasThrottle($route)) {
                $unthrottled[] = implode('|', $route->methods()).' '.$uri.'  →  '.$route->getActionName();
            }
        }

        // گاردِ فیکسچرِ خالی: اگر روزی نشانه‌ها عوض شوند و این جارو به هیچ روتی
        // نرسد، تست باید سرخ شود نه سبز. هشدارِ همیشه-سبز، هشدار نیست.
        $this->assertGreaterThan(
            10,
            $checked,
            'جارو به روتِ گران‌قیمتی نرسید — احتمالاً نشانه‌های EXPENSIVE کهنه شده‌اند.'
        );

        $this->assertSame(
            [],
            $unthrottled,
            "این مسیرها پیامک/سرویسِ پولی/مقایسه‌ی راز دارند ولی throttle ندارند:\n  ".
            implode("\n  ", $unthrottled)
        );
    }

    #[Test]
    public function a_throttled_request_answers_in_the_projects_own_envelope(): void
    {
        // ۴۲۹ از دو مسیرِ متفاوت می‌آید و فرانت باید بتواند هر دو را یکسان بخواند:
        //
        //   * limiterهایی که خودشان `response()` دارند (`sms`, `otp-verify`,
        //     `financial`) — پیامِ فارسیِ خودشان را می‌دهند.
        //   * `throttle:N,1`های ساده — استثنای لاراول را بالا می‌دهند، که
        //     `Handler::handleApiException()` به همان پاکت تبدیلش می‌کند.
        //
        // شاخه‌ی دوم تا امروز پیامِ پیش‌فرضِ **انگلیسیِ** لاراول را می‌داد
        // («Too Many Attempts.») در اپی که `locale=fa` است. کلیدِ ترجمه‌اش
        // (`global.too_many_requests`) از قبل وجود داشت و فقط وصل نبود.
        // نقش لازم است: `role.employer` پیش از سقفِ روت اجرا می‌شود، پس کاربرِ
        // بی‌نقش ۴۰۳ می‌گیرد و هرگز به ۴۲۹ نمی‌رسد.
        $user = User::factory()->create();
        $user->addRole(Role::firstOrCreate(['name' => 'employer'], ['display_name' => 'Employer']));

        Sanctum::actingAs($user);

        // `/profile/elite-yars/invite` سقفِ ساده‌ی ۳ در دقیقه دارد. بدنه‌ی نامعتبر
        // می‌فرستیم: هدف رسیدن به ۴۲۹ است، نه موفق‌شدنِ خودِ دعوت.
        $last = null;
        for ($i = 0; $i < 6; $i++) {
            $last = $this->postJson('/api/profile/elite-yars/invite', []);

            if ($last->getStatusCode() === 429) {
                break;
            }
        }

        $this->assertSame(429, $last->getStatusCode(), 'سقف باید پس از چند تلاش فعال شود.');

        $body = $last->json();

        $this->assertArrayHasKey('status', $body, 'پاسخِ ۴۲۹ باید همان پاکتِ استانداردِ پروژه باشد.');
        $this->assertFalse($body['status']);
        $this->assertSame(__('global.too_many_requests'), $body['message']);
    }
}
