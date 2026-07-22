<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('engineer_profiles', function (Blueprint $table) {
            $table->unsignedBigInteger('activity_province_id')->after('province_id')->nullable();
            $table->unsignedBigInteger('activity_city_id')->after('city_id')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('engineer_profiles', function (Blueprint $table) {
            if (Schema::hasColumn('engineer_profiles', 'activity_province_id')) {
                $table->dropColumn('activity_province_id');
            }
            if (Schema::hasColumn('engineer_profiles', 'activity_city_id')) {
                $table->dropColumn('activity_city_id');
            }
        });
    }
};
