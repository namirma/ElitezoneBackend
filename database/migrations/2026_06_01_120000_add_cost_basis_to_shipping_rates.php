<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Make freight rates UNIT-AWARE. The original table only billed by weight
 * (flat_cost + per_kg_cost × kg). Some goods are billed by COUNT instead
 * (e.g. آجر / وال‌مش — per piece/pack), per the factory/supplier's directive.
 *
 * cost_basis selects which variable component applies for the route:
 *   - 'weight' → flat_cost + per_kg_cost   × total weight (kg)   [default, legacy behaviour]
 *   - 'count'  → flat_cost + per_count_cost × total sales count
 *
 * Additive + backward compatible: existing rows default to 'weight' with
 * per_count_cost = 0, so they price exactly as before.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shipping_rates', function (Blueprint $table) {
            $table->string('cost_basis', 20)->default('weight')->after('per_kg_cost');
            $table->decimal('per_count_cost', 20, 2)->default(0)->after('cost_basis');
        });
    }

    public function down(): void
    {
        Schema::table('shipping_rates', function (Blueprint $table) {
            $table->dropColumn(['cost_basis', 'per_count_cost']);
        });
    }
};
