<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A shipment is one delivery leg of an order: items from ONE origin (factory location)
 * to ONE destination (address), with its own freight cost and (future) tracking status.
 *
 * An order has one or more shipments. This is what makes multi-origin AND multi-destination
 * orders possible: rebar from Kavir → Tehran and brick from Zagros → Isfahan become two
 * shipments under the same order, each priced on its own origin→destination route.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shipments', function (Blueprint $table) {
            $table->id();

            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();

            // Origin
            $table->unsignedBigInteger('factory_id')->nullable();
            $table->unsignedBigInteger('factory_location_id')->nullable();

            // Destination
            $table->unsignedBigInteger('address_id')->nullable();
            $table->unsignedBigInteger('destination_province_id')->nullable();
            $table->unsignedBigInteger('destination_city_id')->nullable();

            $table->decimal('weight', 20, 3)->default(0);
            $table->decimal('shipping_cost', 20, 2)->default(0);

            // Per-shipment lifecycle (future delivery tracking); mirrors order statuses loosely.
            $table->string('status')->default('pending');

            $table->timestamps();

            $table->index('order_id');
            $table->index(['factory_location_id', 'address_id'], 'shipments_origin_dest_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shipments');
    }
};
