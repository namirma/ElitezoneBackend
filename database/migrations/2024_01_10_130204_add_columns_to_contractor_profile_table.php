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
        Schema::table('contractor_profiles', function (Blueprint $table) {
            $table->boolean('not_related')->after('company_name')->default(1);
            $table->string('activity_provinces', 128)->after('activity_history')->nullable();

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('contractor_profiles', function (Blueprint $table) {
            //
        });
    }
};
