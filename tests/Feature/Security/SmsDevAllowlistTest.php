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
 * `php artisan queue:work` برای تخلیهٔ صفِ انباشته، جاب‌های پیامکیِ قدیمی را اجرا
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
