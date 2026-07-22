<?php

namespace Tests\Feature\Audit;

use App\Jobs\Financial\CheckSupplierBalancesJob;
use App\Jobs\Financial\PollPodiumDepositsJob;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Regression tests for the second re-audit batch (decision-item safe fixes).
 *
 * @group audit-fixes
 */
class ReAuditFix2Test extends TestCase
{
    use DatabaseTransactions;

    /** D6 — the two per-minute money jobs must be singletons (ShouldBeUnique). */
    public function test_scheduled_money_jobs_are_unique(): void
    {
        $this->assertInstanceOf(ShouldBeUnique::class, new CheckSupplierBalancesJob);
        $this->assertInstanceOf(ShouldBeUnique::class, new PollPodiumDepositsJob);
        $this->assertSame('check-supplier-balances', (new CheckSupplierBalancesJob)->uniqueId());
        $this->assertSame('poll-podium-deposits', (new PollPodiumDepositsJob)->uniqueId());
    }

    /** D5 — stock_quantity column is now decimal (fractional kg storage, no int drift). */
    public function test_stock_quantity_column_is_decimal(): void
    {
        $col = DB::selectOne("SHOW COLUMNS FROM product_variants WHERE Field = 'stock_quantity'");
        $this->assertNotNull($col);
        $this->assertStringContainsStringIgnoringCase('decimal', $col->Type);
    }
}
