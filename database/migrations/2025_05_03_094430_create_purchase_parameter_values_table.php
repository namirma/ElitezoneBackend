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
        Schema::create('purchase_parameter_values', function (Blueprint $table) {
            $table->id();
            $table->foreignId('group_id')->constrained('purchase_parameter_groups')->onDelete('cascade');
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('code')->unique();
            $table->text('description')->nullable();
            $table->integer('order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->foreignId('price_filter_category_id')->nullable()->constrained();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('purchase_parameter_values');
    }
};
