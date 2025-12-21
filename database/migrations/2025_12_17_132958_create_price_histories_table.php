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
    Schema::create('price_histories', function (Blueprint $table) {
        $table->id();
        $table->foreignId('product_variant_id')
              ->constrained()
              ->onDelete('cascade');
        $table->foreignId('product_price_id')
              ->nullable()
              ->constrained()
              ->onDelete('set null');
        $table->foreignId('factory_id')->nullable()->constrained();
        $table->foreignId('factory_location_id')->nullable()->constrained();
        $table->decimal('general_price', 20, 0)->nullable();
        $table->decimal('special_price', 20, 0)->nullable();
        $table->decimal('golden_figure', 20, 0)->nullable();

        $table->foreignId('user_id')->nullable()->constrained();

        $table->timestamps();
        
        $table->index(['product_variant_id', 'created_at']);
    });
}
    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('price_histories');
    }
};
