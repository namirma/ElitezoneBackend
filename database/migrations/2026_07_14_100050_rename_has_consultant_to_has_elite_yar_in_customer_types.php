<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Stage 3 rename: customer_types.has_consultant → has_elite_yar.
 * (Sibling of the employer_profiles.has_consultant rename — both boolean flags.)
 *
 * Raw ALTER ... CHANGE (MariaDB 10.4, no RENAME COLUMN / no dbal). Definition
 * verified against SHOW FULL COLUMNS FROM customer_types: tinyint(1) NOT NULL DEFAULT 0.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE customer_types CHANGE has_consultant has_elite_yar TINYINT(1) NOT NULL DEFAULT 0');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE customer_types CHANGE has_elite_yar has_consultant TINYINT(1) NOT NULL DEFAULT 0');
    }
};
