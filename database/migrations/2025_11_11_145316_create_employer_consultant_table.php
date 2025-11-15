<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employer_consultant', function (Blueprint $table) {
            $table->id();

            $table->foreignId('employer_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('consultant_id')->constrained('users')->cascadeOnDelete();
            $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending');
            $table->timestamps();
            $table->unique(['employer_id', 'consultant_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employer_consultant');
    }
};
