<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('engineer_profiles', function (Blueprint $table) {
            // کاربر خوداظهاری می‌کند که عضو «سازه نود» (سازه ۹۰) است. بعداً با API
            // ناظرِ سازه‌نود تطبیق داده می‌شود تا badge_id / badge_purchase_amount وارد شود.
            $table->boolean('is_saze90_user')->default(false)->after('engineer_member');
        });
    }

    public function down(): void
    {
        Schema::table('engineer_profiles', function (Blueprint $table) {
            $table->dropColumn('is_saze90_user');
        });
    }
};
