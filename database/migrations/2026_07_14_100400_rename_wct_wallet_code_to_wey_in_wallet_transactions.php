<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Stage 3 rename: the EliteYAR wallet ledger code 'WCT' → 'WEY'.
 *
 * The wallet code is persisted only inside the JSON `wallet_transactions.meta`
 * column (a `longtext`, written by WalletTransactionEngine as {"code":"WCT","op":…}).
 * There is no dedicated code column. This backfills the code in already-persisted
 * rows so historical EliteYAR-wallet transactions match the new constant value
 * (FinancialCodes::WALLET_ELITE_YAR). New rows already use 'WEY' post-deploy.
 *
 * REPLACE() only rewrites the exact "code":"WCT" token and leaves the rest of the
 * meta (op, etc.) untouched. The WHERE guard makes it a no-op where no match exists
 * (0 matching rows on the local DB at authoring time; included so staging/production
 * with real ledger history picks it up on migrate). Mirrors the 2026_07_05_100100
 * description backfill migration's pattern.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('wallet_transactions')
            ->where('meta', 'like', '%"code":"WCT"%')
            ->update(['meta' => DB::raw('REPLACE(meta, \'"code":"WCT"\', \'"code":"WEY"\')')]);
    }

    public function down(): void
    {
        DB::table('wallet_transactions')
            ->where('meta', 'like', '%"code":"WEY"%')
            ->update(['meta' => DB::raw('REPLACE(meta, \'"code":"WEY"\', \'"code":"WCT"\')')]);
    }
};
