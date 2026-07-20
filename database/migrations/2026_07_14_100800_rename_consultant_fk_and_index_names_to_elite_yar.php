<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Stage 3 tail: rename the auto-generated FK-constraint + index NAMES that still
 * carry the old "consultant" token (the underlying columns/tables were already
 * renamed; only the catalog identifiers lagged). These names are never referenced
 * by application code — only visible in SHOW CREATE TABLE — so this is purely a
 * catalog-consistency pass.
 *
 * MariaDB 10.4 has no RENAME INDEX / RENAME KEY, so each is DROP + re-ADD. FKs are
 * dropped before their backing indexes, then re-added with clean names (which
 * recreate the backing index). ON DELETE behaviour is restated exactly:
 *   orders.elite_yar_user_id            → SET NULL
 *   employer_elite_yar.*                → CASCADE
 *   elite_yar_registered_clients.*      → CASCADE
 * Verified up+down+full-suite on laravel_testing before shipping.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── orders ────────────────────────────────────────────────────────────
        DB::statement('ALTER TABLE orders DROP FOREIGN KEY orders_consultant_user_id_foreign');
        DB::statement('ALTER TABLE orders DROP INDEX orders_consultant_user_id_foreign');
        DB::statement('ALTER TABLE orders ADD CONSTRAINT orders_elite_yar_user_id_foreign FOREIGN KEY (elite_yar_user_id) REFERENCES users(id) ON DELETE SET NULL');

        // ── employer_elite_yar (pivot) ────────────────────────────────────────
        DB::statement('ALTER TABLE employer_elite_yar DROP FOREIGN KEY employer_consultant_employer_id_foreign');
        DB::statement('ALTER TABLE employer_elite_yar DROP FOREIGN KEY employer_consultant_consultant_id_foreign');
        DB::statement('ALTER TABLE employer_elite_yar DROP INDEX employer_consultant_consultant_id_foreign');
        DB::statement('ALTER TABLE employer_elite_yar DROP INDEX employer_consultant_employer_id_consultant_id_unique');
        DB::statement('ALTER TABLE employer_elite_yar ADD UNIQUE employer_elite_yar_employer_id_elite_yar_id_unique (employer_id, elite_yar_id)');
        DB::statement('ALTER TABLE employer_elite_yar ADD CONSTRAINT employer_elite_yar_employer_id_foreign FOREIGN KEY (employer_id) REFERENCES users(id) ON DELETE CASCADE');
        DB::statement('ALTER TABLE employer_elite_yar ADD CONSTRAINT employer_elite_yar_elite_yar_id_foreign FOREIGN KEY (elite_yar_id) REFERENCES users(id) ON DELETE CASCADE');

        // ── elite_yar_registered_clients ──────────────────────────────────────
        DB::statement('ALTER TABLE elite_yar_registered_clients DROP FOREIGN KEY consultant_registered_clients_client_user_id_foreign');
        DB::statement('ALTER TABLE elite_yar_registered_clients DROP FOREIGN KEY consultant_registered_clients_consultant_user_id_foreign');
        DB::statement('ALTER TABLE elite_yar_registered_clients DROP INDEX consultant_registered_clients_client_user_id_foreign');
        DB::statement('ALTER TABLE elite_yar_registered_clients DROP INDEX consultant_client_unique');
        DB::statement('ALTER TABLE elite_yar_registered_clients ADD UNIQUE elite_yar_client_unique (elite_yar_user_id, client_user_id)');
        DB::statement('ALTER TABLE elite_yar_registered_clients ADD CONSTRAINT elite_yar_registered_clients_elite_yar_user_id_foreign FOREIGN KEY (elite_yar_user_id) REFERENCES users(id) ON DELETE CASCADE');
        DB::statement('ALTER TABLE elite_yar_registered_clients ADD CONSTRAINT elite_yar_registered_clients_client_user_id_foreign FOREIGN KEY (client_user_id) REFERENCES users(id) ON DELETE CASCADE');
    }

    public function down(): void
    {
        // ── elite_yar_registered_clients ──────────────────────────────────────
        DB::statement('ALTER TABLE elite_yar_registered_clients DROP FOREIGN KEY elite_yar_registered_clients_client_user_id_foreign');
        DB::statement('ALTER TABLE elite_yar_registered_clients DROP FOREIGN KEY elite_yar_registered_clients_elite_yar_user_id_foreign');
        DB::statement('ALTER TABLE elite_yar_registered_clients DROP INDEX elite_yar_registered_clients_client_user_id_foreign');
        DB::statement('ALTER TABLE elite_yar_registered_clients DROP INDEX elite_yar_client_unique');
        DB::statement('ALTER TABLE elite_yar_registered_clients ADD UNIQUE consultant_client_unique (elite_yar_user_id, client_user_id)');
        DB::statement('ALTER TABLE elite_yar_registered_clients ADD CONSTRAINT consultant_registered_clients_consultant_user_id_foreign FOREIGN KEY (elite_yar_user_id) REFERENCES users(id) ON DELETE CASCADE');
        DB::statement('ALTER TABLE elite_yar_registered_clients ADD CONSTRAINT consultant_registered_clients_client_user_id_foreign FOREIGN KEY (client_user_id) REFERENCES users(id) ON DELETE CASCADE');

        // ── employer_elite_yar (pivot) ────────────────────────────────────────
        DB::statement('ALTER TABLE employer_elite_yar DROP FOREIGN KEY employer_elite_yar_employer_id_foreign');
        DB::statement('ALTER TABLE employer_elite_yar DROP FOREIGN KEY employer_elite_yar_elite_yar_id_foreign');
        DB::statement('ALTER TABLE employer_elite_yar DROP INDEX employer_elite_yar_elite_yar_id_foreign');
        DB::statement('ALTER TABLE employer_elite_yar DROP INDEX employer_elite_yar_employer_id_elite_yar_id_unique');
        DB::statement('ALTER TABLE employer_elite_yar ADD UNIQUE employer_consultant_employer_id_consultant_id_unique (employer_id, elite_yar_id)');
        DB::statement('ALTER TABLE employer_elite_yar ADD CONSTRAINT employer_consultant_employer_id_foreign FOREIGN KEY (employer_id) REFERENCES users(id) ON DELETE CASCADE');
        DB::statement('ALTER TABLE employer_elite_yar ADD CONSTRAINT employer_consultant_consultant_id_foreign FOREIGN KEY (elite_yar_id) REFERENCES users(id) ON DELETE CASCADE');

        // ── orders ────────────────────────────────────────────────────────────
        DB::statement('ALTER TABLE orders DROP FOREIGN KEY orders_elite_yar_user_id_foreign');
        DB::statement('ALTER TABLE orders DROP INDEX orders_elite_yar_user_id_foreign');
        DB::statement('ALTER TABLE orders ADD CONSTRAINT orders_consultant_user_id_foreign FOREIGN KEY (elite_yar_user_id) REFERENCES users(id) ON DELETE SET NULL');
    }
};
