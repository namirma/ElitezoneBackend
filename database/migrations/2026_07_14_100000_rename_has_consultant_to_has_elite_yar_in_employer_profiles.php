<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Stage 3 rename: employer_profiles.has_consultant → has_elite_yar.
 *
 * MariaDB 10.4 has no `RENAME COLUMN` (added in 10.5.2) and doctrine/dbal is not
 * installed, so `$table->renameColumn()` is unavailable. A raw `ALTER ... CHANGE`
 * with the full column definition restated is used — this matches the project's
 * existing raw-SQL migration convention (see the 2026_07_05 rename migrations).
 *
 * Definition verified against `SHOW FULL COLUMNS FROM employer_profiles`:
 *   has_consultant  tinyint(1)  NOT NULL  DEFAULT 0
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE employer_profiles CHANGE has_consultant has_elite_yar TINYINT(1) NOT NULL DEFAULT 0');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE employer_profiles CHANGE has_elite_yar has_consultant TINYINT(1) NOT NULL DEFAULT 0');
    }
};
