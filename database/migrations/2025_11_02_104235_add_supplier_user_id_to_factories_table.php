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
        Schema::table('factories', function (Blueprint $table) {
            $table->foreignId('supplier_user_id')->nullable()
                ->after('id')
                ->constrained('users')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('factories', function (Blueprint $table) {
            if (Schema::hasColumn('factories', 'supplier_user_id')) {
                $table->dropForeign(['supplier_user_id']);
                $table->dropColumn('supplier_user_id');
            }
        });
    }
};
