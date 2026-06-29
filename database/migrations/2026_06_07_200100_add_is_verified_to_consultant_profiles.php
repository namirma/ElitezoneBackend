<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * فلگ «مشاور تأییدشده» روی پروفایل مهندس و پیمانکار.
 *
 * is_verified: true وقتی ادمین مدارک تحصیلی/حرفه‌ای مشاور را از بک‌آفیس تأیید کرد.
 * verified_at: زمان تأیید (برای audit trail).
 *
 * کارفرما فقط می‌تواند مشاور verified را دعوت کند یا در سفارش انتخاب کند.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('engineer_profiles', function (Blueprint $table) {
            $table->boolean('is_verified')->default(false)->after('user_id');
            $table->timestamp('verified_at')->nullable()->after('is_verified');
        });

        Schema::table('contractor_profiles', function (Blueprint $table) {
            $table->boolean('is_verified')->default(false)->after('user_id');
            $table->timestamp('verified_at')->nullable()->after('is_verified');
        });
    }

    public function down(): void
    {
        Schema::table('engineer_profiles', function (Blueprint $table) {
            $table->dropColumn(['is_verified', 'verified_at']);
        });

        Schema::table('contractor_profiles', function (Blueprint $table) {
            $table->dropColumn(['is_verified', 'verified_at']);
        });
    }
};
