<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Record HOW each shipment leg was billed, so the freight on an order is fully
 * auditable/reproducible:
 *   - billed_count: the total sales count carried by the leg (the count-basis driver)
 *   - cost_basis:   which basis priced the leg ('weight' | 'count')
 *
 * weight was already stored; this adds the count + basis alongside it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->decimal('billed_count', 20, 3)->default(0)->after('weight');
            $table->string('cost_basis', 20)->default('weight')->after('billed_count');
        });
    }

    public function down(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->dropColumn(['billed_count', 'cost_basis']);
        });
    }
};
