<?php

namespace Tests\Feature\Audit;

use App\Models\PriceSetting;
use App\Services\Financial\SettlementCalculatorService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * D7-1 — the transaction-commission rate (%TC) is now a global, editable setting
 * (PriceSetting: tc_percentage) instead of a hardcoded constant.
 *
 * @group audit-fixes
 */
class SettlementConfigTest extends TestCase
{
    use DatabaseTransactions;

    public function test_tc_percentage_defaults_to_one_percent_when_unset(): void
    {
        Cache::forget('price_setting_'.PriceSetting::CODE_TC_PERCENTAGE);

        $c = app(SettlementCalculatorService::class)->calculateCommissions(1_000_000, null);

        // 1% of 1,000,000 = 10,000
        $this->assertSame(10_000.0, $c['TC']);
    }

    public function test_tc_percentage_is_read_from_global_setting(): void
    {
        PriceSetting::create([
            PriceSetting::NAME => 'TC rate',
            PriceSetting::CODE => PriceSetting::CODE_TC_PERCENTAGE,
            PriceSetting::VALUE => '2',
        ]); // saved() event flushes the cache

        $c = app(SettlementCalculatorService::class)->calculateCommissions(1_000_000, null);

        // 2% of 1,000,000 = 20,000 — proves the rate is no longer hardcoded
        $this->assertSame(20_000.0, $c['TC']);
    }
}
