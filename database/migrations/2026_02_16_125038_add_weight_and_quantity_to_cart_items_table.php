<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cart_items', function (Blueprint $table) {
            $table->decimal('weight', 15, 3)->default(0)->after('quantities')
                ->comment('Total logistics weight in KG');

            $table->decimal('quantity', 15, 2)->default(0)->after('weight')
                ->comment('Total quantity count');

            $table->string('unit_name')->nullable()->after('quantity')
                ->comment('Snapshot of the unit name (e.g., Bundle, Branch)');
        });
    }

    public function down(): void
    {
        Schema::table('cart_items', function (Blueprint $table) {
            $table->dropColumn(['weight', 'quantity', 'unit_name']);
        });
    }
};
