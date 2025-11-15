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
        Schema::create('stock_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('product_variant_id')->constrained('product_variants')->cascadeOnDelete();
            $table->timestamp('notified_at')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'product_variant_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_subscriptions');
    }
};
