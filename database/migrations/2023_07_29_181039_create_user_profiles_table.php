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
            Schema::create('user_profiles', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id');
                $table->string('name', 32)->nullable();
                $table->string('family', 64)->nullable();
                $table->string('email', 64)->unique()->nullable();
                $table->timestamp('email_verified_at')->nullable();
                $table->string('national_code', 16);
                $table->string('birthday', 16)->nullable();
                $table->string('national_id', 16)->nullable();
                $table->string('company', 64)->nullable();
                $table->string('register_id', 32)->nullable();
                $table->string('phone', 32)->nullable();
                $table->unsignedBigInteger('province_id')->nullable();
                $table->unsignedBigInteger('city_id')->nullable();
                $table->enum('entity', ['real', 'legal'])->default('real');
                $table->timestamps();
                $table->foreign('user_id')
                    ->references('id')
                    ->on('users')
                    ->onDelete('cascade')
                    ->onUpdate('cascade');
            });
        }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_profiles');
    }
};
