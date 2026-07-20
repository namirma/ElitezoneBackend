<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Backfills the «مشاور / مشاور فنی» → «الیت یار» rebrand into already-seeded DISPLAY
 * NAME rows that the earlier rename touched only in the seeders (the live rows were
 * never re-seeded, so they still read «مشاور»). Surfaced as a real bug: the profile
 * card showed «مشاور فنی» as the EliteYAR user-type name (users.type.name comes from
 * user_types.name), and the pricing category/filter names still read «... مشاور».
 *
 * - user_types (slug=expert): «مشاور فنی» → «الیت یار» (drop «فنی», match UserTypeSeeder).
 * - customer_types / price_filters / price_filter_types / price_filter_categories:
 *   REPLACE «مشاور» → «الیت یار» in name (+ description). Guarded with NOT LIKE «مشاوره»
 *   so the consultation-service word (a different term) is never corrupted.
 *
 * Idempotent + no-op where already clean, so staging/production pick it up on migrate.
 */
return new class extends Migration
{
    private const REPLACE_TABLES = [
        'customer_types',
        'price_filters',
        'price_filter_types',
        'price_filter_categories',
        'purchase_parameter_groups',
        'purchase_parameter_values',
    ];

    public function up(): void
    {
        DB::table('user_types')->where('slug', 'expert')->where('name', 'مشاور فنی')->update(['name' => 'الیت یار']);

        foreach (self::REPLACE_TABLES as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            foreach (['name', 'description'] as $col) {
                if (! Schema::hasColumn($table, $col)) {
                    continue;
                }
                DB::table($table)
                    ->where($col, 'like', '%مشاور%')
                    ->where($col, 'not like', '%مشاوره%')
                    ->update([$col => DB::raw("REPLACE($col, 'مشاور', 'الیت یار')")]);
            }
        }
    }

    public function down(): void
    {
        DB::table('user_types')->where('slug', 'expert')->where('name', 'الیت یار')->update(['name' => 'مشاور فنی']);

        foreach (self::REPLACE_TABLES as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            foreach (['name', 'description'] as $col) {
                if (! Schema::hasColumn($table, $col)) {
                    continue;
                }
                DB::table($table)
                    ->where($col, 'like', '%الیت یار%')
                    ->update([$col => DB::raw("REPLACE($col, 'الیت یار', 'مشاور')")]);
            }
        }
    }
};
