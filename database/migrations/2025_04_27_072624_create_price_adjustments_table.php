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
        Schema::create('price_adjustments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_type_id')->constrained()->onDelete('cascade');
            $table->foreignId('badge_id')->nullable()->constrained()->onDelete('set null');
            $table->decimal('adjustment_amount', 20, 0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['customer_type_id', 'badge_id'], 'unique_customer_badge_adjustment');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('price_adjustments');
    }
};
