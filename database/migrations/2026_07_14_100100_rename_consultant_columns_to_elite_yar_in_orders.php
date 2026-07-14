<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Stage 3 rename: the five consultant-named columns on `orders` → elite_yar.
 *
 *   consultant_user_id          → elite_yar_user_id          (has FK to users, ON DELETE SET NULL)
 *   consultant_share_rate       → elite_yar_share_rate
 *   payment_to_consultant       → payment_to_elite_yar
 *   consultant_moadian_ref      → elite_yar_moadian_ref
 *   consultant_hold_released_at → elite_yar_hold_released_at
 *
 * Raw `ALTER ... CHANGE` (MariaDB 10.4: no RENAME COLUMN, no doctrine/dbal).
 * Verified on this MariaDB that CHANGE renames an FK column in place and the
 * foreign key follows automatically (the auto-generated FK/index *names* keep
 * the old "consultant" token — internal identifiers, not referenced by code).
 * Definitions verified against SHOW FULL COLUMNS FROM orders. Comments are also
 * updated to «الیت یار» in the same statement.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE orders CHANGE consultant_user_id elite_yar_user_id BIGINT(20) UNSIGNED NULL COMMENT 'الیت یار که کمیسیون CO را دریافت می‌کند'");
        DB::statement("ALTER TABLE orders CHANGE consultant_share_rate elite_yar_share_rate DECIMAL(5,2) NOT NULL DEFAULT 0.00 COMMENT '%CO: درصد سهم الیت یار'");
        DB::statement("ALTER TABLE orders CHANGE payment_to_consultant payment_to_elite_yar DECIMAL(20,2) NULL DEFAULT NULL COMMENT 'PTC: پرداختی به الیت یار'");
        DB::statement("ALTER TABLE orders CHANGE consultant_moadian_ref elite_yar_moadian_ref VARCHAR(100) NULL DEFAULT NULL COMMENT 'Doc Q: شماره فاکتور مودیان صادر شده توسط الیت یار'");
        DB::statement("ALTER TABLE orders CHANGE consultant_hold_released_at elite_yar_hold_released_at TIMESTAMP NULL DEFAULT NULL COMMENT 'زمان آزادسازی هولد کمیسیون الیت یار'");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE orders CHANGE elite_yar_user_id consultant_user_id BIGINT(20) UNSIGNED NULL COMMENT 'الیت یار که کمیسیون CO را دریافت می‌کند'");
        DB::statement("ALTER TABLE orders CHANGE elite_yar_share_rate consultant_share_rate DECIMAL(5,2) NOT NULL DEFAULT 0.00 COMMENT '%CO: درصد سهم مشاور'");
        DB::statement("ALTER TABLE orders CHANGE payment_to_elite_yar payment_to_consultant DECIMAL(20,2) NULL DEFAULT NULL COMMENT 'PTC: پرداختی به مشاور'");
        DB::statement("ALTER TABLE orders CHANGE elite_yar_moadian_ref consultant_moadian_ref VARCHAR(100) NULL DEFAULT NULL COMMENT 'Doc Q: شماره فاکتور مودیان صادر شده توسط الیت یار'");
        DB::statement("ALTER TABLE orders CHANGE elite_yar_hold_released_at consultant_hold_released_at TIMESTAMP NULL DEFAULT NULL COMMENT 'زمان آزادسازی هولد کمیسیون مشاور'");
    }
};
