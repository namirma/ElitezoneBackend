<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agreements', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->index();
            $table->string('version')->default('1.0');
            $table->text('content')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('is_mandatory')->default(true);
            $table->timestamps();

            $table->unique(['slug', 'version']);
        });

        Schema::create('user_agreements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('agreement_id')->constrained()->onDelete('cascade');
            $table->timestamp('accepted_at');
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_agreements');
        Schema::dropIfExists('agreements');
    }
};
