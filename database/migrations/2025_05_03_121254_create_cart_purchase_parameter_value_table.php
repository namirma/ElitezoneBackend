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
        Schema::create('cart_purchase_parameter_value', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cart_id')->constrained()->onDelete('cascade');

            $table->unsignedBigInteger('purchase_parameter_value_id');
            $table->foreign('purchase_parameter_value_id', 'cppv_param_value_fk')
                ->references('id')
                ->on('purchase_parameter_values')
                ->onDelete('cascade');

            $table->timestamps();

            $table->unique(['cart_id', 'purchase_parameter_value_id'], 'cart_param_value_unique');
        });

    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cart_purchase_parameter_value');
    }
};
