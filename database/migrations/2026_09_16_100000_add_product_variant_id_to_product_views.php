<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * بازدیدِ صفحه‌ی محصولِ سایت روی **واریانت** است (`/product/{variantId}`)، ولی
 * `product_views` فقط `product_id` داشت و `product_variants.view_count` هیچ
 * نویسنده‌ای نداشت — پس «پربازدیدترین» در فهرستِ واریانت‌ها هیچ داده‌ای نداشت.
 * ستون nullable می‌ماند: بازدیدِ صفحه‌ی محصولِ اصلی همچنان بدونِ واریانت ثبت می‌شود.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_views', function (Blueprint $table) {
            $table->foreignId('product_variant_id')
                ->nullable()
                ->after('product_id')
                ->constrained('product_variants')
                ->nullOnDelete();
            $table->index(['product_variant_id', 'viewed_at'], 'product_views_variant_viewed_idx');
        });
    }

    public function down(): void
    {
        Schema::table('product_views', function (Blueprint $table) {
            $table->dropIndex('product_views_variant_viewed_idx');
            $table->dropConstrainedForeignId('product_variant_id');
        });
    }
};
