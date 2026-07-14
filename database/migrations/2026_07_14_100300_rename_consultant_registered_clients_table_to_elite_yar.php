<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Stage 3 rename: table consultant_registered_clients → elite_yar_registered_clients,
 * and its column consultant_user_id → elite_yar_user_id.
 *
 * `consultant_user_id` has an FK to users (ON DELETE CASCADE) and is the leading
 * column of the composite UNIQUE `consultant_client_unique`. Raw CHANGE renames it
 * in place (FK + unique index follow; their names keep the old token — internal).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('RENAME TABLE consultant_registered_clients TO elite_yar_registered_clients');
        DB::statement('ALTER TABLE elite_yar_registered_clients CHANGE consultant_user_id elite_yar_user_id BIGINT(20) UNSIGNED NOT NULL');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE elite_yar_registered_clients CHANGE elite_yar_user_id consultant_user_id BIGINT(20) UNSIGNED NOT NULL');
        DB::statement('RENAME TABLE elite_yar_registered_clients TO consultant_registered_clients');
    }
};
