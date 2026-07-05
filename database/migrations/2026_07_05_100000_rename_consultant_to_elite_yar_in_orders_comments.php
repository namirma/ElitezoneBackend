<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Updates the two `orders` column COMMENTs left over from the "مشاور فنی" →
 * "الیت یار" rebrand (commit 5f6747f renamed every display string and
 * PHP-side docblock, but a MySQL column COMMENT is schema metadata baked in
 * at migration-run time — editing the original migration files does not
 * retroactively change it on an already-migrated database).
 *
 * Raw ALTER...MODIFY is used (not doctrine/dbal's ->change()) so the exact
 * existing type/nullability is restated verbatim and only the comment text
 * changes — verified against `SHOW FULL COLUMNS FROM orders` before writing
 * this migration. Foreign keys are separate constraint objects in MySQL and
 * are unaffected by a column-comment-only MODIFY.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(
            "ALTER TABLE orders MODIFY consultant_user_id BIGINT UNSIGNED NULL COMMENT 'الیت یار که کمیسیون CO را دریافت می‌کند'"
        );

        DB::statement(
            "ALTER TABLE orders MODIFY consultant_moadian_ref VARCHAR(100) NULL COMMENT 'Doc Q: شماره فاکتور مودیان صادر شده توسط الیت یار'"
        );
    }

    public function down(): void
    {
        DB::statement(
            "ALTER TABLE orders MODIFY consultant_user_id BIGINT UNSIGNED NULL COMMENT 'مشاور فنی که کمیسیون CO را دریافت می‌کند'"
        );

        DB::statement(
            "ALTER TABLE orders MODIFY consultant_moadian_ref VARCHAR(100) NULL COMMENT 'Doc Q: شماره فاکتور مودیان صادر شده توسط مشاور فنی'"
        );
    }
};
