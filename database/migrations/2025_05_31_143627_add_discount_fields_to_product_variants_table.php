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
        Schema::table('product_variants', function (Blueprint $table) {
            $table->decimal('discount_value', 10, 2)->nullable()->after('view_count');
            $table->string('discount_type')->nullable()->after('discount_value');
            $table->timestamp('discount_start_date')->nullable()->after('discount_type');
            $table->timestamp('discount_end_date')->nullable()->after('discount_start_date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('product_variants', function (Blueprint $table) {
            $table->dropColumn(['discount_value', 'discount_type']);
        });
    }
};
