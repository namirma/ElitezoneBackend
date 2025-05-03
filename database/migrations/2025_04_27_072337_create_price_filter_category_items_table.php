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
        Schema::create('price_filter_category_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('price_filter_category_id')->constrained()->onDelete('cascade');
            $table->foreignId('price_filter_id')->constrained()->onDelete('cascade');
            $table->timestamps();

            $table->unique(['price_filter_category_id', 'price_filter_id'], 'unique_filter_category_item');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('price_filter_category_items');
    }
};
