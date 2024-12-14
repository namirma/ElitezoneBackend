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
        Schema::create('supplier_activities', function (Blueprint $table) {
            $table->foreignId('supplier_id')->constrained('supplier_profiles')->onDelete('cascade');
            $table->foreignId('activity_category_id')->constrained('supplier_activity_categories')->onDelete('cascade');

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('supplier_activities');
    }
};
