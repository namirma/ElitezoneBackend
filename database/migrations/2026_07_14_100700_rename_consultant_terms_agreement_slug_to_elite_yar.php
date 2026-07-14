<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Stage 3 tail: the agreement slug 'consultant_terms' → 'elite_yar_terms'.
 *
 * SignAgreementAction looks up Agreement::where(slug, 'consultant_terms') for the
 * 'expert' role. The lookup string was renamed in code, so the persisted
 * agreements.slug row must move in lockstep. Exact-match UPDATE; no-op if the row
 * doesn't exist (0 rows on the dev DB at authoring time — production may carry it).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('agreements')) {
            DB::table('agreements')->where('slug', 'consultant_terms')->update(['slug' => 'elite_yar_terms']);
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('agreements')) {
            DB::table('agreements')->where('slug', 'elite_yar_terms')->update(['slug' => 'consultant_terms']);
        }
    }
};
