<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * بنرها به سایت وصل می‌شوند (۲۰۲۶-۰۹-۱۴).
 *
 * - `banners.category_id`: یک بنرِ `category_banner` باید بگوید بنرِ کدام
 *   دسته‌بندی است، وگرنه صفحه‌ی دسته‌بندی نمی‌داند کدام را نشان دهد.
 *   `nullOnDelete` چون حذفِ دسته نباید بنر را پاک کند — فقط بی‌مقصدش می‌کند.
 * - `banners.subtitle`: خطِ دومِ بنر (زیرِ عنوان) — همان جایگاهی که هیروِ
 *   صفحه‌ی دسته‌بندی همیشه داشت ولی متنش هاردکد بود (`GR-1`).
 * - `product_categories.subtitle`: fallbackِ همان خط وقتی بنری برای دسته
 *   ثبت نشده.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('banners', function (Blueprint $table) {
            $table->string('subtitle')->nullable()->after('title');
            $table->foreignId('category_id')
                ->nullable()
                ->after('position')
                ->constrained('product_categories')
                ->nullOnDelete();
        });

        Schema::table('product_categories', function (Blueprint $table) {
            $table->string('subtitle')->nullable()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('banners', function (Blueprint $table) {
            $table->dropConstrainedForeignId('category_id');
            $table->dropColumn('subtitle');
        });

        Schema::table('product_categories', function (Blueprint $table) {
            $table->dropColumn('subtitle');
        });
    }
};
