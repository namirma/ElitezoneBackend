<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * هر کاربر برای هر محصول فقط یک نظر — ارسال مجدد، همان رکورد را ویرایش و
 * به صف pending برمی‌گرداند (ProductReviewController::store).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_reviews', function (Blueprint $table) {
            $table->unique(['user_id', 'product_id'], 'product_reviews_user_product_unique');
        });
    }

    public function down(): void
    {
        Schema::table('product_reviews', function (Blueprint $table) {
            $table->dropUnique('product_reviews_user_product_unique');
        });
    }
};
