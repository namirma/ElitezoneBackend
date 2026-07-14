<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Stage 3 tail: the last DB-level "consultant" trace — the column COMMENT on
 * users.badge_purchase_amount — updated to «elite yar». A column comment is schema
 * metadata baked in at migration-run time, so a raw ALTER ... MODIFY (comment-only;
 * exact type/nullability restated) is required to change it on an already-migrated DB.
 * Verified against SHOW FULL COLUMNS FROM users: decimal(20,2) NULL DEFAULT NULL.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE users MODIFY badge_purchase_amount DECIMAL(20,2) NULL DEFAULT NULL COMMENT 'Invoice amount (Rials) the elite yar paid for their badge; basis for the lifetime commission cap. NULL = uncapped.'");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE users MODIFY badge_purchase_amount DECIMAL(20,2) NULL DEFAULT NULL COMMENT 'Invoice amount (Rials) the consultant paid for their badge; basis for the lifetime commission cap. NULL = uncapped.'");
    }
};
