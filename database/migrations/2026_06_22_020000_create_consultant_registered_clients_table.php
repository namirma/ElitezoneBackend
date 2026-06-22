<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('consultant_registered_clients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('consultant_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('client_user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            // مشاور یک بار می‌تواند هر مشتری را ثبت کند
            $table->unique(['consultant_user_id', 'client_user_id'], 'consultant_client_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('consultant_registered_clients');
    }
};
