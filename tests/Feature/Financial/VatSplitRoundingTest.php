<?php

namespace Tests\Feature\Financial;

use App\Services\Financial\SettlementCalculatorService;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * یک بدهکار، دو بستانکار — جمعشان باید در **دقتِ ذخیره‌سازی** دقیق باشد.
 *
 * `extractVat()` تضمین می‌کند `net + vat === gross` با دو رقم اعشار، ولی ستون‌های
 * کیف پول `decimal(20,0)`اند. پس هر لِگ جداگانه به ریالِ صحیح گرد می‌شد و هرجا
 * کسرِ اعشاریِ net و vat دو طرفِ ۰٫۵ می‌افتاد، مجموعِ دو بستانکار یک ریال از
 * بدهکار فاصله می‌گرفت — یعنی یک ریال خلق یا نابود می‌شد.
 *
 * `WeighbridgeVatRouter::routeEliteYAR()` تنها جای سیستم است که یک بدهکار به دو
 * بستانکارِ مستقل تقسیم می‌شود، پس تنها جایی است که این اتفاق می‌افتاد.
 */
class VatSplitRoundingTest extends TestCase
{
    private SettlementCalculatorService $calc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->calc = app(SettlementCalculatorService::class);
    }

    #[Test]
    public function the_two_credits_always_sum_to_the_single_debit(): void
    {
        // جاروب روی بازه‌ی واقعیِ کمیسیون با گام‌های اعشاری، تا حالت‌های مرزیِ
        // گردکردن واقعاً دیده شوند — نه یک نمونه‌ی خوش‌شانس.
        $mismatches = 0;
        $checked = 0;

        for ($i = 0; $i < 4000; $i++) {
            $gross = 1_000_000 + ($i * 1_000.37);

            ['gross' => $g, 'net' => $net, 'vat' => $vat] = $this->calc->extractVatInRials($gross, 10.0);

            $checked++;
            if ($net + $vat !== $g) {
                $mismatches++;
            }
        }

        $this->assertSame(0, $mismatches, "در {$checked} حالت، {$mismatches} بار مجموعِ دو بستانکار با بدهکار برابر نبود.");
    }

    #[Test]
    public function every_leg_is_a_whole_rial(): void
    {
        // اگر لِگی اعشار داشته باشد، ستونِ decimal(20,0) خودش گردش می‌کند و همان
        // ناترازی از راهِ دیگری برمی‌گردد.
        foreach ([1_000_000.49, 4_166_667.445833, 999_999.5, 12_345_678.91] as $gross) {
            ['gross' => $g, 'net' => $net, 'vat' => $vat] = $this->calc->extractVatInRials($gross, 10.0);

            $this->assertSame($g, round($g));
            $this->assertSame($net, round($net));
            $this->assertSame($vat, round($vat));
        }
    }

    #[Test]
    public function the_documented_failing_case_now_balances(): void
    {
        // موردی که در ممیزی گزارش شد: pool = 10,000,001.88 → gross ≈ 4,166,667.445833
        // ذخیره می‌شد به‌صورت بدهکار 4,166,667 و بستانکارِ 3,787,880 + 378,788
        // = 4,166,668 → یک ریال خلق‌شده.
        ['gross' => $g, 'net' => $net, 'vat' => $vat] = $this->calc->extractVatInRials(4_166_667.445833, 10.0);

        $this->assertSame(4_166_667.0, $g);
        $this->assertSame($g, $net + $vat, 'دقیقاً همان حالتی که یک ریال خلق می‌کرد.');
    }

    #[Test]
    public function a_zero_vat_rate_puts_everything_in_net_and_still_balances(): void
    {
        // نرخ صفر روی `price_settings` قابلِ ثبت است. آن موقع لِگِ مالیات صفر
        // می‌شود و روتر ردش می‌کند — تراز باید همچنان برقرار بماند.
        ['gross' => $g, 'net' => $net, 'vat' => $vat] = $this->calc->extractVatInRials(1_234_567.89, 0.0);

        $this->assertSame(0.0, $vat);
        $this->assertSame($g, $net);
        $this->assertSame($g, $net + $vat);
    }

    #[Test]
    public function a_non_positive_gross_yields_nothing(): void
    {
        foreach ([0.0, -5.0, 0.4] as $gross) {
            $split = $this->calc->extractVatInRials($gross, 10.0);
            $this->assertSame(0.0, $split['gross']);
            $this->assertSame(0.0, $split['net']);
            $this->assertSame(0.0, $split['vat']);
        }
    }
}
