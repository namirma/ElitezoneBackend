<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * نظرات محصول + صف تأیید ادمین (الگوی صف‌های job-experiences).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->unsignedTinyInteger('rating');       // 1..5
            $table->text('comment')->nullable();
            $table->string('status')->default('pending'); // pending | approved | rejected
            $table->string('rejection_reason', 1000)->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index('product_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_reviews');
    }
};
