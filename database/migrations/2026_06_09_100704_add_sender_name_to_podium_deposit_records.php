<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * افزودن نام فرستنده به podium_deposit_records
 *
 * sender_name — نام صاحب حساب فرستنده از ItemSystemMetaData.Main_Sys_Meta_Data.TrxSrcProductOwnerName
 *              برای تسهیل بررسی ادمین در واریزهای unmatched (پل) ذخیره می‌شود.
 *              nullable چون پادیوم همیشه این فیلد را برنمی‌گرداند.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('podium_deposit_records', function (Blueprint $table) {
            $table->string('sender_name', 128)->nullable()->after('sender_iban')
                ->comment('نام فرستنده از ItemSystemMetaData پادیوم (TrxSrcProductOwnerName)');
        });
    }

    public function down(): void
    {
        Schema::table('podium_deposit_records', function (Blueprint $table) {
            $table->dropColumn('sender_name');
        });
    }
};
