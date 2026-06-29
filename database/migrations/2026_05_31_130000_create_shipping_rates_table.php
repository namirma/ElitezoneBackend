<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Freight rate table: cost to ship from an ORIGIN (a specific factory location)
 * to a DESTINATION (province, optionally a specific city).
 *
 * Cost model = flat_cost (per shipment leg) + per_kg_cost × weight. A row with a
 * null destination_city_id is the province-level fallback; a city-specific row
 * overrides it. When no row matches a route, shipping is treated as 0 (so the
 * platform keeps working until an admin enters the rates).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shipping_rates', function (Blueprint $table) {
            $table->id();

            $table->foreignId('factory_location_id')
                ->constrained('factory_locations')
                ->cascadeOnDelete();

            // Destination — mirrors addresses.province_id / addresses.city_id.
            // Plain indexed integers (no FK) because the province/city reference
            // tables are not Eloquent-managed migrations in this project.
            $table->unsignedBigInteger('destination_province_id');
            $table->unsignedBigInteger('destination_city_id')->nullable();

            $table->decimal('flat_cost', 20, 2)->default(0);
            $table->decimal('per_kg_cost', 20, 2)->default(0);
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->unique(
                ['factory_location_id', 'destination_province_id', 'destination_city_id'],
                'shipping_rates_route_unique'
            );
            $table->index(['factory_location_id', 'destination_province_id'], 'shipping_rates_origin_dest_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shipping_rates');
    }
};
