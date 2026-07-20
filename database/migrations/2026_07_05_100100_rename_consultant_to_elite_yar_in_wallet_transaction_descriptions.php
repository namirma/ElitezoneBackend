<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Backfills the "مشاور فنی" → "الیت یار" rebrand into already-persisted
 * `wallet_transactions.description` rows. The source code that WRITES these
 * descriptions (WeighbridgeVatRouter, ReleaseEliteYARHoldAction) was fixed
 * in commit 5f6747f, so this migration only needs to touch rows created
 * BEFORE that fix — new transactions already get the new text.
 *
 * REPLACE() only rewrites the matched substring and leaves everything else
 * in the description (order numbers, etc.) untouched. The WHERE clause means
 * this is a no-op wherever no matching row exists — confirmed zero matches
 * on this local database at authoring time, but the fix is included so any
 * environment with real historical data (staging/production) picks it up
 * automatically on `php artisan migrate`.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('wallet_transactions')
            ->where('description', 'like', '%مشاور فنی%')
            ->update([
                'description' => DB::raw("REPLACE(description, 'مشاور فنی', 'الیت یار')"),
            ]);
    }

    public function down(): void
    {
        DB::table('wallet_transactions')
            ->where('description', 'like', '%الیت یار%')
            ->update([
                'description' => DB::raw("REPLACE(description, 'الیت یار', 'مشاور فنی')"),
            ]);
    }
};
