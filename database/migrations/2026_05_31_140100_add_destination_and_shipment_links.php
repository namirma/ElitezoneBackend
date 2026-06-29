<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-line destination on the cart, and the order_item → shipment link.
 *
 * - cart_items.address_id: the destination chosen for that line (enables multi-destination
 *   carts; null falls back to the order's primary address at placement).
 * - order_items.shipment_id: which shipment (origin→destination leg) the line belongs to.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cart_items', function (Blueprint $table) {
            $table->unsignedBigInteger('address_id')->nullable()->after('factory_location_id');
        });

        Schema::table('order_items', function (Blueprint $table) {
            $table->unsignedBigInteger('shipment_id')->nullable()->after('order_id');
            $table->index('shipment_id');
        });
    }

    public function down(): void
    {
        Schema::table('cart_items', function (Blueprint $table) {
            $table->dropColumn('address_id');
        });

        Schema::table('order_items', function (Blueprint $table) {
            $table->dropIndex(['shipment_id']);
            $table->dropColumn('shipment_id');
        });
    }
};
