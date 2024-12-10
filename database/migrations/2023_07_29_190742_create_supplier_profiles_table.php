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
        Schema::create('supplier_profiles', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('activity_description')->nullable();
            $table->string('brands')->nullable();
            $table->unsignedInteger('activity_history')->nullable();
            $table->string('production_amount')->nullable()->comment('میزان تولید');
            $table->string('standards')->nullable()->comment('استاندارد‌های کسب شده');
            $table->string('website', 128)->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('supplier_profiles');
    }
};
