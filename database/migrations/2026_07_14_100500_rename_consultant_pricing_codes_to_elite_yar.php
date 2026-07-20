<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Stage 3 rename: persisted pricing-role codes consultant → elite_yar.
 *
 * These codes live in the `code` column of six catalog/pricing tables and are the
 * runtime keys the pricing pipeline matches on (PricingRoleResolver, CartResolver,
 * PriceFilterCategoryDetectorService, …). The application code was updated in the
 * same commit; this migration renames the already-seeded rows so live data matches.
 *
 * Exact-match UPDATEs (no substring surprises); a table without a matching row is a
 * no-op (0 rows on the local DB at authoring time — production carries the seeded
 * rows and picks this up on migrate). The pricing cache key is flushed so the next
 * request rebuilds categories under the new codes.
 */
return new class extends Migration
{
    private const TABLES = [
        'customer_types',
        'price_filter_categories',
        'price_filter_types',
        'price_filters',
        'purchase_parameter_groups',
        'purchase_parameter_values',
    ];

    public function up(): void
    {
        $this->rename([
            'employer_without_consultant' => 'employer_without_elite_yar',
            'engineer_with_consultant' => 'engineer_with_elite_yar',
            'with_consultant' => 'with_elite_yar',
            'without_consultant' => 'without_elite_yar',
            'consultant_status' => 'elite_yar_status',
        ]);
    }

    public function down(): void
    {
        $this->rename([
            'employer_without_elite_yar' => 'employer_without_consultant',
            'engineer_with_elite_yar' => 'engineer_with_consultant',
            'with_elite_yar' => 'with_consultant',
            'without_elite_yar' => 'without_consultant',
            'elite_yar_status' => 'consultant_status',
        ]);
    }

    private function rename(array $map): void
    {
        foreach (self::TABLES as $table) {
            if (! Schema::hasColumn($table, 'code')) {
                continue;
            }
            foreach ($map as $old => $new) {
                DB::table($table)->where('code', $old)->update(['code' => $new]);
            }
        }

        Cache::forget('pricing:categories_with_filters');
    }
};
