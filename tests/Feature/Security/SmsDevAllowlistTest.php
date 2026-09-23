<?php

namespace Tests\Feature\Security;

use App\Services\SmsService;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * بیرون از پروداکشن، پیامک فقط به شماره‌های allowlist می‌رود.
 *
 * پیشینه (نشست ۲۲): کردنشیالِ واقعیِ TSMS در `.env` توسعه هم هست. یک
 * `php artisan queue:work` برای تخلیه‌ی صفِ انباشته، جاب‌های پیامکیِ قدیمی را اجرا
 * کرد و **به شماره‌های واقعیِ کاربران پیامک رفت**. هیچ گاردی وجود نداشت.
 *
 * این تست‌ها گارد را pin می‌کنند. اگر کسی شرطِ محیط را بردارد، اینجا سرخ می‌شود.
 */
class SmsDevAllowlistTest extends TestCase
{
    private function configureGateway(): void
    {
        config()->set('services.tsms.link', 'https://sms.test/send');
        config()->set('services.tsms.number', '3000');
        config()->set('services.tsms.username', 'u');
        config()->set('services.tsms.password', 'p');
    }

    #[Test]
    public function a_number_outside_the_allowlist_is_never_contacted(): void
    {
        $this->configureGateway();
        config()->set('services.tsms.dev_allowlist', ['09130603199']);
        Http::fake();

        $result = app(SmsService::class)->send('09121234567', 'سلام');

        Http::assertNothingSent();
        // true یعنی «کاری نکن و retry هم نکن» — نه «تحویل شد».
        $this->assertTrue($result);
    }

    #[Test]
    public function an_allowlisted_number_still_goes_out(): void
    {
        $this->configureGateway();
        config()->set('services.tsms.dev_allowlist', ['09130603199']);
        Http::fake(['https://sms.test/*' => Http::response('ok', 200)]);

        app(SmsService::class)->send('09130603199', 'سلام');

        Http::assertSentCount(1);
    }

    #[Test]
    public function the_allowlist_matches_regardless_of_number_format(): void
    {
        // ‌`+98…`/`98…`/`0098…` همان شماره‌اند؛ گارد نباید با شکلِ نوشتن دور بخورد.
        $this->configureGateway();
        config()->set('services.tsms.dev_allowlist', ['+989130603199']);
        Http::fake(['https://sms.test/*' => Http::response('ok', 200)]);

        app(SmsService::class)->send('09130603199', 'سلام');

        Http::assertSentCount(1);
    }

    #[Test]
    public function an_empty_allowlist_blocks_everything(): void
    {
        // fail-safe: یک clone تازه نباید بتواند به هیچ‌کس پیامک بدهد.
        $this->configureGateway();
        config()->set('services.tsms.dev_allowlist', []);
        Http::fake();

        app(SmsService::class)->send('09130603199', 'سلام');

        Http::assertNothingSent();
    }

    /** `deliveryProblem()` را در محیطی دیگر بخوان و محیط را در هر حالت برگردان. */
    private function deliveryProblemIn(string $env): ?string
    {
        app()->detectEnvironment(fn () => $env);

        try {
            return SmsService::deliveryProblem();
        } finally {
            app()->detectEnvironment(fn () => 'testing');
        }
    }

    #[Test]
    public function a_deployed_non_production_environment_with_an_empty_allowlist_is_a_reported_problem(): void
    {
        // همان تله‌ای که `APP_ENV=staging` را با فراموش‌کردنِ allowlist جفت می‌کند:
        // پیامکِ OTP نمی‌رود، jobها موفق علامت می‌خورند، و هیچ خطایی لاگ نمی‌شود.
        $this->configureGateway();
        config()->set('services.tsms.dev_allowlist', []);

        $problem = $this->deliveryProblemIn('staging');

        $this->assertNotNull($problem);
        $this->assertStringContainsString('staging', $problem);
    }

    #[Test]
    public function an_unconfigured_gateway_outside_production_is_the_same_outage_and_is_also_reported(): void
    {
        // علتش فرق دارد، نتیجه‌اش یکی است: کسی نمی‌تواند وارد شود.
        config()->set('services.tsms.link', '');
        config()->set('services.tsms.number', '');
        config()->set('services.tsms.dev_allowlist', ['09130603199']);

        $this->assertNotNull($this->deliveryProblemIn('staging'));
    }

    #[Test]
    public function a_developer_laptop_is_deliberately_not_flagged(): void
    {
        // فهرستِ خالی روی `local` حالتِ **درست** است (پیش‌فرضِ یک clone تازه).
        // هشداری که روی هر لپ‌تاپی شلیک کند خاموش می‌شود، و آن‌وقت روی استیجینگ
        // هم کسی نگاهش نمی‌کند.
        $this->configureGateway();
        config()->set('services.tsms.dev_allowlist', []);

        $this->assertNull($this->deliveryProblemIn('local'));
        $this->assertNull($this->deliveryProblemIn('production'));
    }

    #[Test]
    public function a_staging_with_the_allowlist_filled_reports_nothing(): void
    {
        $this->configureGateway();
        config()->set('services.tsms.dev_allowlist', ['09130603199']);

        $this->assertNull($this->deliveryProblemIn('staging'));
    }

    #[Test]
    public function allow_all_on_a_deployed_staging_reaches_an_unlisted_number(): void
    {
        // علی ۱۴۰۵/۰۷/۰۱: استیجینگ باید مثلِ شرایطِ عادی به همه پیامک بدهد.
        $this->configureGateway();
        config()->set('services.tsms.dev_allowlist', []);
        config()->set('services.tsms.dev_allow_all', true);
        app()->detectEnvironment(fn () => 'staging');
        Http::fake(['https://sms.test/*' => Http::response('ok', 200)]);

        try {
            app(SmsService::class)->send('09121234567', 'سلام');
            Http::assertSentCount(1);
            // و دیگر «لیستِ خالی» به‌عنوانِ قطعیِ ورود گزارش نمی‌شود.
            $this->assertNull(SmsService::deliveryProblem());
        } finally {
            app()->detectEnvironment(fn () => 'testing');
        }
    }

    #[Test]
    public function allow_all_never_reaches_real_numbers_from_a_laptop(): void
    {
        // خودِ حادثه‌ای که این گارد برایش ساخته شد: `.env` استیجینگ روی لپ‌تاپ کپی شود.
        $this->configureGateway();
        config()->set('services.tsms.dev_allowlist', []);
        config()->set('services.tsms.dev_allow_all', true);
        Http::fake();

        foreach (['local', 'testing'] as $env) {
            app()->detectEnvironment(fn () => $env);
            try {
                app(SmsService::class)->send('09121234567', 'سلام');
            } finally {
                app()->detectEnvironment(fn () => 'testing');
            }
        }

        Http::assertNothingSent();
    }

    #[Test]
    public function production_ignores_the_allowlist_entirely(): void
    {
        $this->configureGateway();
        config()->set('services.tsms.dev_allowlist', []);
        app()->detectEnvironment(fn () => 'production');
        Http::fake(['https://sms.test/*' => Http::response('ok', 200)]);

        try {
            app(SmsService::class)->send('09121234567', 'سلام');
            Http::assertSentCount(1);
        } finally {
            app()->detectEnvironment(fn () => 'testing');
        }
    }
}
