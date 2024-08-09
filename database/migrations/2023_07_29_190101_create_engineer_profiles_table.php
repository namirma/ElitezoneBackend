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
        Schema::create('engineer_profiles', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->boolean('engineer_member');
            $table->string('license_number', 32)->nullable()->comment('شماره پروانه اشتغال');
            $table->string('supervision_base', 32)->nullable()->comment('پایه نظارت');
            $table->string('design_base')->nullable()->comment('پایه طراحی');
            $table->string('member_number')->nullable()->comment('شماره عضویت');
            $table->string('fields_of_study')->nullable()->comment('رشته مهندسی');
            $table->boolean('student');
            $table->unsignedBigInteger('province_id')->nullable();
            $table->unsignedBigInteger('city_id')->nullable();
            $table->string('university_name', 128)->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('engineer_profiles');
    }
};
