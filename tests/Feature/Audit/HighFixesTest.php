<?php

namespace Tests\Feature\Audit;

use App\Services\Reports\CsvReportBuilder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Regression tests for the High-severity audit fixes.
 *
 * @group audit-fixes
 */
class HighFixesTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * CSV formula-injection: cells starting with = + - @ (or tab/CR) must be
     * neutralized with a leading quote; ordinary values pass through untouched.
     */
    public function test_csv_cells_are_sanitized_against_formula_injection(): void
    {
        $builder = app(CsvReportBuilder::class);

        $this->assertSame("'=cmd|'/c calc'!A0", $builder->sanitizeCell("=cmd|'/c calc'!A0"));
        $this->assertSame("'+1", $builder->sanitizeCell('+1'));
        $this->assertSame("'-2", $builder->sanitizeCell('-2'));
        $this->assertSame("'@x", $builder->sanitizeCell('@x'));

        // Safe values are untouched.
        $this->assertSame('Ali Rezaei', $builder->sanitizeCell('Ali Rezaei'));
        $this->assertSame(1200, $builder->sanitizeCell(1200));
        $this->assertSame('', $builder->sanitizeCell(''));
    }

    /**
     * Payment callback must not accept arbitrary verbs (was Route::any).
     * DELETE is now rejected with 405.
     */
    public function test_payment_callback_rejects_unsafe_verbs(): void
    {
        $this->deleteJson('/api/payment/callback')->assertStatus(405);
    }

    /**
     * Product list search + attribute filter must build valid SQL (previously the
     * params were validated but silently dropped; the attribute filter path also
     * exercises a nested whereHas that must not throw).
     */
    public function test_product_search_and_attribute_filter_do_not_crash(): void
    {
        $this->getJson('/api/products?search=test')->assertStatus(200);
        $this->getJson('/api/products?filter_categories[]=1&filter_categories[]=2')->assertStatus(200);
    }

    /**
     * national_code must carry a unique index (fresh schema has no duplicate data,
     * so the defensive migration adds it).
     */
    public function test_national_code_has_unique_index(): void
    {
        $exists = DB::table('information_schema.statistics')
            ->where('table_schema', DB::getDatabaseName())
            ->where('table_name', 'user_profiles')
            ->where('index_name', 'user_profiles_national_code_unique')
            ->exists();

        $this->assertTrue($exists, 'expected unique index on user_profiles.national_code');
    }
}
