<?php

namespace Tests\Feature\Audit;

use App\Http\Controllers\Api\Admin\AdminActivityLogController;
use App\Models\PriceSetting;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Regression tests for the Medium-severity audit fixes.
 *
 * @group audit-fixes
 */
class MediumFixesTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * PriceSetting::getValueAsFloat must be cache-backed and must invalidate the
     * cache when the underlying setting changes (VAT rate etc. propagate at once).
     */
    public function test_price_setting_float_is_cached_and_invalidated(): void
    {
        $setting = PriceSetting::create([
            PriceSetting::NAME => 'VAT',
            PriceSetting::CODE => 'test_vat_rate',
            PriceSetting::VALUE => '9',
        ]);

        $this->assertSame(9.0, PriceSetting::getValueAsFloat('test_vat_rate'));
        $this->assertTrue(Cache::has('price_setting_test_vat_rate'));

        // Updating the setting must flush the cache so the new value is read.
        $setting->update([PriceSetting::VALUE => '10']);
        $this->assertSame(10.0, PriceSetting::getValueAsFloat('test_vat_rate'));
    }

    /**
     * Missing performance indexes must exist after migration.
     */
    public function test_performance_indexes_exist(): void
    {
        $expected = [
            'wallet_transactions' => 'wt_wallet_status_idx',
            'order_items' => 'order_items_factory_id_idx',
            'shipments' => 'shipments_factory_id_idx',
        ];

        foreach ($expected as $table => $index) {
            $exists = DB::table('information_schema.statistics')
                ->where('table_schema', DB::getDatabaseName())
                ->where('table_name', $table)
                ->where('index_name', $index)
                ->exists();

            $this->assertTrue($exists, "expected index {$index} on {$table}");
        }
    }

    /**
     * Activity-log subject-type allowlist must include the newly-audited models.
     */
    public function test_activity_log_allowlist_includes_coupon_and_supplier_profile(): void
    {
        $ref = new \ReflectionMethod(AdminActivityLogController::class, 'index');
        // The allowlist lives inline; assert via a request would need an admin user.
        // Instead, assert the source contains the new entries (lightweight guard).
        $source = file_get_contents($ref->getFileName());
        $this->assertStringContainsString("'Coupon'", $source);
        $this->assertStringContainsString("'SupplierProfile'", $source);
    }
}
