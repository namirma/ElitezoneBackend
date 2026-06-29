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
        Schema::create('cart_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cart_id')->constrained()->onDelete('cascade');
            $table->foreignId('product_variant_id')->constrained()->onDelete('cascade');
            $table->foreignId('factory_id')->constrained();
            $table->foreignId('factory_location_id')->constrained();
            $table->foreignId('shipping_method_id')->constrained();
            $table->json('quantities');
            $table->decimal('price', 20, 0);
            $table->decimal('final_price', 20, 0);
            $table->decimal('vat_amount', 20, 0)->default(0);
            $table->decimal('total_with_vat', 20, 0)->default(0);
            $table->boolean('with_vat')->default(false);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cart_items');
    }
};
