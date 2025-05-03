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
        Schema::create('cart_price_filter_categories', function (Blueprint $table) {
            $table->foreignId('cart_id')->constrained()->onDelete('cascade');
            $table->foreignId('price_filter_category_id')->constrained()->onDelete('cascade');
            $table->timestamps();

            $table->unique(['cart_id', 'price_filter_category_id'], 'unique_cart_filter_category');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cart_price_filter_categories');
    }
};
