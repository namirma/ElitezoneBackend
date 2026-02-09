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
        Schema::table('job_experiences', function (Blueprint $table) {
            $table->text('project_address')->nullable()->after('organization_name');
        });
    }

    public function down(): void
    {
        Schema::table('job_experiences', function (Blueprint $table) {
            $table->dropColumn('project_address');
        });
    }
};
