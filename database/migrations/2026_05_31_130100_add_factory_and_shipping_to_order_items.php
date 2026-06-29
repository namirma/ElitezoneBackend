<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Persist each order line's ORIGIN factory + location and its allocated shipping cost.
 *
 * Previously order_items only kept supplier_user_id, so the factory of origin was lost
 * at placement and per-factory freight could not be reconstructed. These columns let the
 * order record where each line ships from and how much its leg cost.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->unsignedBigInteger('factory_id')->nullable()->after('supplier_user_id');
            $table->unsignedBigInteger('factory_location_id')->nullable()->after('factory_id');
            $table->decimal('shipping_cost', 20, 2)->default(0)->after('total_price');
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropColumn(['factory_id', 'factory_location_id', 'shipping_cost']);
        });
    }
};
