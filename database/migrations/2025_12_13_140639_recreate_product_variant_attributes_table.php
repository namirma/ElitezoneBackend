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
        Schema::dropIfExists('product_variant_attributes');

        Schema::create('product_variant_attributes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_variant_id')
                ->constrained('product_variants')
                ->cascadeOnDelete();
            $table->foreignId('attribute_id')
                ->constrained('attributes')
                ->cascadeOnDelete();
            $table->foreignId('attribute_value_id')
                ->constrained('attribute_values')
                ->cascadeOnDelete();

            $table->unique(['product_variant_id', 'attribute_id'], 'unique_pva_variant_attr');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('product_variant_attributes');
    }
};
