<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cart_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cart_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('session_id')->nullable()->index();
            $table->foreignId('product_variant_id')->constrained();
            $table->string('action_type', 20);
            $table->decimal('quantity_change', 15, 3);
            $table->decimal('unit_price_snapshot', 20, 0)->nullable();
            $table->timestamps();

            $table->index(['product_variant_id', 'action_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cart_events');
    }
};
