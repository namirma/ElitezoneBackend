<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        
        if (Schema::hasTable('employer_profiles') && !Schema::hasColumn('employer_profiles', 'has_consultant')) {
            Schema::table('employer_profiles', function (Blueprint $table) {
                $table->boolean('has_consultant')->default(false)->after('user_id');
            });
        }
    }

    public function down(): void {
        if (Schema::hasTable('employer_profiles') && Schema::hasColumn('employer_profiles', 'has_consultant')) {
            Schema::table('employer_profiles', function (Blueprint $table) {
                $table->dropColumn('has_consultant');
            });
        }
    }
};
