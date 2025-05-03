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
        Schema::create('product_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_variant_id')->constrained()->onDelete('cascade');
            $table->foreignId('factory_id')->constrained()->onDelete('cascade');
            $table->foreignId('factory_location_id')->constrained()->onDelete('cascade');
            $table->decimal('general_price', 20, 0);
            $table->decimal('special_price', 20, 0);
            $table->decimal('golden_figure', 20, 0);
            $table->timestamp('last_updated_at')->useCurrent();
            $table->timestamps();

            $table->unique(['product_variant_id', 'factory_id', 'factory_location_id'], 'unique_product_price');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('product_prices');
    }
};
