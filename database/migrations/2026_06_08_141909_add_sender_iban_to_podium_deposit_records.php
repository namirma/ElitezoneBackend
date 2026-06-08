<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * افزودن اطلاعات فرستنده و روش تطبیق به podium_deposit_records
 *
 * sender_iban  — شبای فرستنده از ItemSystemMetaData.Main_Sys_Meta_Data.TrxSrcProductId
 *               (برای تراکنش‌های پل هم موجود است)
 *
 * matched_by   — روش شناسایی کاربر:
 *               'shenaseh' = از طریق شناسه واریز (۱۱ رقم)
 *               'iban'     = از طریق شبای فرستنده ↔ UserBankAccount
 *               null       = تطبیق انجام نشده (unmatched)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('podium_deposit_records', function (Blueprint $table) {
            $table->string('sender_iban', 34)->nullable()->after('shenaseh_variz')
                ->comment('شبای فرستنده از ItemSystemMetaData پادیوم (IR + 24 رقم)');

            $table->string('matched_by', 20)->nullable()->after('wallet_txn_id')
                ->comment('روش تطبیق: shenaseh | iban | null');
        });
    }

    public function down(): void
    {
        Schema::table('podium_deposit_records', function (Blueprint $table) {
            $table->dropColumn(['sender_iban', 'matched_by']);
        });
    }
};
