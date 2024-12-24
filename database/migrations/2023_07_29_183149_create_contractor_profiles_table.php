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
        Schema::create('contractor_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete()->cascadeOnUpdate();
            $table->boolean('engineer_member');
            $table->string('company_name', 64)->nullable();
            $table->string('license_number', 32)->nullable()->comment('شماره پروانه اشتغال');
            $table->string('supervision_base', 32)->nullable()->comment('پایه نظارت');
            $table->string('design_base')->nullable()->comment('پایه طراحی');
            $table->unsignedInteger('activity_history')->nullable()->comment('سابقه فعالیت');
            $table->string('project_done')->nullable();
            $table->string('project_in_progress')->nullable();
            $table->boolean('trusted_contractor')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('contractor_profiles');
    }
};
