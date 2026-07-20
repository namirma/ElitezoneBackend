<?php

namespace Tests\Feature\Financial;

use App\Services\Financial\SettlementCalculatorService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Pins the exact financial output of SettlementCalculatorService so the
 * float→bcmath refactor cannot silently change any computed money value.
 *
 * All expected values are hand-computed with exact rational arithmetic;
 * they must hold identically before and after the bcmath migration.
 *
 * Pure computation — no database access.
 */
class SettlementCalculatorServiceTest extends TestCase
{
    private SettlementCalculatorService $calc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->calc = new SettlementCalculatorService;
    }

    #[Test]
    public function it_calculates_commissions_for_gold_badge(): void
    {
        // TC = 1,000,000,000 × 1% = 10,000,000
        // gold: OW 40%, CO 37.5%, SSG 22.5% of TC
        $c = $this->calc->calculateCommissions(1_000_000_000, 'gold');

        $this->assertSame(10_000_000.0, $c['TC']);
        $this->assertSame(4_000_000.0, $c['OW']);
        $this->assertSame(3_750_000.0, $c['CO']);
        $this->assertSame(2_250_000.0, $c['SSG']);
    }

    #[Test]
    public function it_calculates_commissions_for_pioneer_default_badge(): void
    {
        $c = $this->calc->calculateCommissions(1_000_000_000, null);

        $this->assertSame(10_000_000.0, $c['TC']);
        $this->assertSame(4_000_000.0, $c['OW']);
        $this->assertSame(2_500_000.0, $c['CO']);
        $this->assertSame(3_500_000.0, $c['SSG']);
    }

    #[Test]
    #[DataProvider('pooVariants')]
    public function it_calculates_all_four_poo_variants(bool $tc, bool $vat, float $expected): void
    {
        // trans=5,000,000  op=28,000  weight=20,000  ow=0.4%  vat=10%
        $poo = $this->calc->calculatePOO(5_000_000, 28_000, 20_000, 0.4, 10, $tc, $vat);

        $this->assertEqualsWithDelta($expected, $poo, 0.001);
    }

    public static function pooVariants(): array
    {
        return [
            '+TC +VAT' => [true,  true,  619_014_000.0],
            '+TC -VAT' => [true,  false, 618_516_000.0],
            '-TC +VAT' => [false, true,  619_036_000.0],
            '-TC -VAT' => [false, false, 618_536_000.0],
        ];
    }

    #[Test]
    #[DataProvider('ptsVariants')]
    public function it_calculates_all_four_pts_variants(bool $tc, bool $vat, float $expected): void
    {
        // trans=5,000,000 op=28,000 weight=20,000 ow=0.4% ssg=0.225% co=0.375% vat=10%
        $pts = $this->calc->calculatePTS(5_000_000, 28_000, 20_000, 0.4, 0.225, 0.375, 10, $tc, $vat);

        $this->assertEqualsWithDelta($expected, $pts, 0.001);
    }

    public static function ptsVariants(): array
    {
        return [
            '+TC +VAT' => [true,  true,  615_285_000.0],
            '+TC -VAT' => [true,  false, 614_790_000.0],
            '-TC +VAT' => [false, true,  615_340_000.0],
            '-TC -VAT' => [false, false, 614_840_000.0],
        ];
    }

    #[Test]
    public function vat_multiplier_is_exact_for_repeating_decimals(): void
    {
        // 10% VAT → multiplier = 0.1 / 1.1 = 1/11 = 0.0909090909...
        $this->assertEqualsWithDelta(1 / 11, $this->calc->vatMultiplier(10), 1e-9);

        // The whole point of bcmath: 1,100,000,000 × (1/11) = 100,000,000 EXACTLY.
        $split = $this->calc->extractVat(1_100_000_000, 10);
        $this->assertEqualsWithDelta(100_000_000.0, $split['vat'], 0.0001);
        $this->assertEqualsWithDelta(1_000_000_000.0, $split['net'], 0.0001);
    }
}
