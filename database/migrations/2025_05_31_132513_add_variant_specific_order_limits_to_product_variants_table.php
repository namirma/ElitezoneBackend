<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_variants', function (Blueprint $table) {
            $table->decimal('min_order_value', 12, 2)->nullable()->after('stock_quantity');
            $table->decimal('max_order_value', 12, 2)->nullable()->after('min_order_value');
            $table->foreignId('limit_order_unit_id')->nullable()->after('max_order_value')
                ->constrained('order_units')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::table('product_variants', function (Blueprint $table) {
            $table->dropForeign(['limit_order_unit_id']);
            $table->dropColumn(['min_order_value', 'max_order_value', 'limit_order_unit_id']);
        });
    }
};
