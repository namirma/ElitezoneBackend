<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_notes', function (Blueprint $table) {
            $table->id();
            // The user the note is about.
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            // The admin who wrote the note (nullable so a deleted admin keeps the note).
            $table->foreignId('admin_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('note');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_notes');
    }
};
