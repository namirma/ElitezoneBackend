<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Stage 3 rename: pivot table employer_consultant → employer_elite_yar,
 * and its column consultant_id → elite_yar_id.
 *
 * `consultant_id` carries an FK to users (ON DELETE CASCADE) and is part of the
 * composite UNIQUE (employer_id, consultant_id). Raw CHANGE renames the column in
 * place; MariaDB keeps the FK and unique index pointing at it (their internal
 * *names* keep the old token — not code-referenced). Table renamed with RENAME TABLE.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('RENAME TABLE employer_consultant TO employer_elite_yar');
        DB::statement('ALTER TABLE employer_elite_yar CHANGE consultant_id elite_yar_id BIGINT(20) UNSIGNED NOT NULL');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE employer_elite_yar CHANGE elite_yar_id consultant_id BIGINT(20) UNSIGNED NOT NULL');
        DB::statement('RENAME TABLE employer_elite_yar TO employer_consultant');
    }
};
