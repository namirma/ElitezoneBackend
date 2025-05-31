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
        Schema::table('products', function (Blueprint $table) {
            $table->decimal('aggregate_min_order_value', 12, 2)->nullable()->after('items_per_page'); // یا جای مناسب دیگر
            $table->decimal('aggregate_max_order_value', 12, 2)->nullable()->after('aggregate_min_order_value');
            $table->foreignId('aggregate_limit_order_unit_id')->nullable()->after('aggregate_max_order_value')
                ->constrained('order_units')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropForeign(['aggregate_limit_order_unit_id']);
            $table->dropColumn(['aggregate_min_order_value', 'aggregate_max_order_value', 'aggregate_limit_order_unit_id']);
        });
    }
};
