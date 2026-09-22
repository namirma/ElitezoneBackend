<?php

namespace Tests\Feature\Financial;

use App\Actions\Financial\ProcessPodiumDepositAction;
use App\Jobs\Financial\PollPodiumDepositsJob;
use App\Models\Setting;
use App\Services\PodiumServices\DepositStatementService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * مسیرِ «واریز بانکی → شارژ کیف پول» — سه نقصی که واریز را نامرئی می‌کرد.
 *
 * ۱. سقفِ ۵۰ ردیف بدونِ صفحه‌بندی. بانک واریز و برداشت را با هم می‌دهد و فیلترِ
 *    «فقط واریز» *بعد* از بریده‌شدن اجرا می‌شد، پس بازه‌ای با ۵۰ برداشتِ اول
 *    «۰ واریز» گزارش می‌کرد — که از «واریزی نبود» قابل تشخیص نیست.
 * ۲. پنجره‌ی ثابتِ `now()-15min` بدونِ هیچ جبرانی: هر بازه‌ای که worker نمی‌دوید
 *    برای همیشه از دست می‌رفت.
 * ۳. لحظه‌ی UTC به‌عنوان تاریخ/ساعتِ محلیِ بانک فرستاده می‌شد — پنجره ۳٫۵ ساعت عقب.
 */
class PodiumDepositPollingTest extends TestCase
{
    use DatabaseTransactions;

    private const WATERMARK = 'podium.deposits.polled_up_to';

    private function statement(string $doc, int $creditor): array
    {
        return ['DocNumber' => $doc, 'CreditorAmount' => $creditor, 'DebtorAmount' => 0];
    }

    // ───────────────────────── صفحه‌بندی ─────────────────────────

    #[Test]
    public function it_pages_until_a_short_page_and_filters_across_the_whole_range(): void
    {
        config(['podium.deposit.statement.page_size' => 50]);

        // صفحه‌ی اول: ۵۰ برداشت (هیچ واریزی). صفحه‌ی دوم: ۲ واریزِ واقعی.
        $page1 = [];
        for ($i = 0; $i < 50; $i++) {
            $page1[] = $this->statement("DEBIT-{$i}", 0);
        }
        $page2 = [$this->statement('REAL-1', 5_000_000), $this->statement('REAL-2', 7_000_000)];

        $service = Mockery::mock(DepositStatementService::class)->makePartial();
        $service->shouldReceive('getStatements')
            ->once()->with('1405/05/10', '1405/05/10', '10:00:00', '10:15:00', 0, 50)
            ->andReturn($page1);
        $service->shouldReceive('getStatements')
            ->once()->with('1405/05/10', '1405/05/10', '10:00:00', '10:15:00', 50, 50)
            ->andReturn($page2);

        $deposits = $service->getIncomingDeposits('1405/05/10', '1405/05/10', '10:00:00', '10:15:00');

        // پیش از اصلاح این آرایه خالی بود — هر دو واریز پشتِ صفحه‌ی اول گم می‌شدند.
        $this->assertCount(2, $deposits);
        $this->assertSame(['REAL-1', 'REAL-2'], array_column($deposits, 'DocNumber'));
    }

    #[Test]
    public function it_stops_at_the_page_cap_instead_of_looping_forever(): void
    {
        config(['podium.deposit.statement.page_size' => 10, 'podium.deposit.statement.max_pages' => 3]);

        $full = [];
        for ($i = 0; $i < 10; $i++) {
            $full[] = $this->statement("D-{$i}", 1000);
        }

        $service = Mockery::mock(DepositStatementService::class)->makePartial();
        // همیشه صفحه‌ی پر — بدون سقف، حلقه بی‌پایان می‌شد.
        $service->shouldReceive('getStatements')->times(3)->andReturn($full);

        $deposits = $service->getIncomingDeposits('1405/05/10', '1405/05/10');

        $this->assertCount(30, $deposits);
    }

    // ───────────────────────── watermark / جبران ─────────────────────────

    #[Test]
    public function a_missed_window_is_caught_up_from_the_watermark(): void
    {
        // آخرین polling موفق: ۶ ساعت پیش (worker خوابیده بود).
        $watermark = Carbon::now()->subHours(6);
        Setting::updateOrCreate(
            [Setting::KEY => self::WATERMARK],
            [Setting::VALUE => $watermark->toIso8601String()],
        );

        $job = new PollPodiumDepositsJob;
        $from = $this->invokeWindowStart($job, Carbon::now());

        // پنجره باید تا watermark عقب برود، نه فقط ۱۵ دقیقه.
        $this->assertGreaterThan(
            300,
            $from->diffInMinutes(Carbon::now()),
            'پس از یک قطعی، بازه‌ی جامانده باید جبران شود نه اینکه دور انداخته شود.'
        );
    }

    #[Test]
    public function the_lookback_is_capped_after_a_very_long_outage(): void
    {
        config(['podium.deposit.statement.max_lookback_minutes' => 1440]); // ۱ روز

        Setting::updateOrCreate(
            [Setting::KEY => self::WATERMARK],
            [Setting::VALUE => Carbon::now()->subDays(90)->toIso8601String()],
        );

        $job = new PollPodiumDepositsJob;
        $from = $this->invokeWindowStart($job, Carbon::now());

        $this->assertLessThanOrEqual(
            1441,
            $from->diffInMinutes(Carbon::now()),
            'نباید کلِ تاریخ را از بانک بخواهیم.'
        );
    }

    #[Test]
    public function the_first_ever_run_uses_the_default_window(): void
    {
        Setting::where(Setting::KEY, self::WATERMARK)->delete();
        config(['podium.deposit.statement.default_window_minutes' => 15]);

        $job = new PollPodiumDepositsJob;
        $now = Carbon::now();
        $from = $this->invokeWindowStart($job, $now);

        $this->assertEqualsWithDelta(15, $from->diffInMinutes($now), 1);
    }

    // ──────────── گاردِ محیط: پولینگ بیرون از production خاموش است ────────────

    /*
     * چرا این گارد هست: جاب صورت‌حسابِ **واقعیِ** بانکیِ شرکت را می‌خواند و هر
     * واریزِ شناسه‌دار را به کیف پولِ همان محیط می‌نشاند. روی استیجینگ یعنی واریزِ
     * واقعیِ یک مشتری، کیف پولِ آزمایشی را شارژ می‌کند. تا قبل از این هیچ کلیدِ
     * خاموش‌کردنی نبود (`podium.settlement.enabled` فقط برداشت است) و تنها راه
     * «خالی‌کردنِ دستیِ دو کلیدِ .env روی سرور» بود — یک قاعده‌ی ایمنی که به یادِ
     * یک آدم سپرده شده بود.
     */

    #[Test]
    public function polling_disabled_stops_the_job_before_it_reads_the_bank(): void
    {
        config(['podium.deposit.polling_enabled' => false]);

        // هر تماسی با سرویسِ صورت‌حساب یعنی گارد کار نکرده — پس اصلاً نباید صدا شود.
        $service = Mockery::mock(DepositStatementService::class);
        $service->shouldNotReceive('getStatements');
        $service->shouldNotReceive('getIncomingDeposits');

        $action = Mockery::mock(ProcessPodiumDepositAction::class);
        $action->shouldNotReceive('execute');

        $before = Setting::where(Setting::KEY, self::WATERMARK)->value(Setting::VALUE);

        (new PollPodiumDepositsJob)->handle($service, $action);

        // و watermark هم جلو نرفته باشد: یک جابِ ردشده نباید بازه را «خوانده‌شده»
        // علامت بزند، وگرنه روشن‌کردنِ بعدیِ پولینگ همان بازه را برای همیشه می‌بازد.
        $this->assertSame(
            $before,
            Setting::where(Setting::KEY, self::WATERMARK)->value(Setting::VALUE),
            'جابِ خاموش watermark را جلو برد — بازه‌ی خوانده‌نشده برای همیشه گم می‌شود.'
        );
    }

    #[Test]
    public function the_schedule_is_gated_at_source(): void
    {
        /*
         * زمان‌بندی در بوت ثبت می‌شود، پس یک `config()` داخلِ تست دیر است — همان
         * دلیلی که `Product/ProductReviewRoutesAreGatedTest` گیت را در سطحِ سورس
         * پین می‌کند. این‌جا هم همان کار: می‌سنجیم که خطِ زمان‌بندی داخلِ شرط است.
         *
         * دو لایه داریم و این تست لایه‌ی بیرونی را می‌پاید: بدونِ آن، سرور هر ۵
         * دقیقه جاب را صدا می‌زند و فقط لاگِ هشدار می‌گیریم؛ با آن، `schedule:list`
         * روی سرور خودش نشان می‌دهد که پولینگ نیست.
         */
        $source = file_get_contents(base_path('routes/console.php'));

        $this->assertMatchesRegularExpression(
            '/if\s*\(\s*config\(\s*[\'"]podium\.deposit\.polling_enabled[\'"]\s*\)\s*\)\s*\{[^}]*PollPodiumDepositsJob/s',
            $source,
            'زمان‌بندیِ PollPodiumDepositsJob دیگر پشتِ شرطِ podium.deposit.polling_enabled نیست.'
        );
    }

    private function invokeWindowStart(PollPodiumDepositsJob $job, Carbon $to): Carbon
    {
        $m = new \ReflectionMethod($job, 'windowStart');
        $m->setAccessible(true);

        return $m->invoke($job, $to);
    }
}
