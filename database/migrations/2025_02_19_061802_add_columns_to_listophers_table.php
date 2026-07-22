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
        Schema::table('listophers', function (Blueprint $table) {
            $table->enum('type', ['stirrups', 'rebars'])->index()->after('project_code');
        });
        Schema::table('listopher_items', function (Blueprint $table) {
            $table->decimal('b')->nullable();
            $table->decimal('h')->nullable();

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('listophers', function (Blueprint $table) {
            if (Schema::hasColumn('listophers', 'type')) {
                $table->dropColumn('type');
            }
        });
        Schema::table('listopher_items', function (Blueprint $table) {
            if (Schema::hasColumn('listopher_items', 'b')) {
                $table->dropColumn('b');
            }
            if (Schema::hasColumn('listopher_items', 'h')) {
                $table->dropColumn('h');
            }
        });
    }
};
