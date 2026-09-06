<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * دسته‌بندی سوالات متداول — تبدیل «رشته‌ی آزاد» به موجودیت واقعی.
 *
 * تا امروز `faqs.category` فقط یک رشته بود، ولی کشوی پشتیبانیِ سایت اصلی برای
 * هر دسته یک «عنوان + زیرعنوان + ترتیب نمایش» می‌خواهد (SupportMenu.jsx) — و
 * رشته‌ی آزاد هیچ‌کدام را نمی‌تواند بدهد. نتیجه این بود که کل آن منو در
 * `features/support/data/supportData.js` هاردکد مانده بود و اصلاً به پنل وصل نبود.
 *
 * ستون قدیمیِ `faqs.category` دست‌نخورده می‌ماند (سازگاری عقب‌رو با فیلترِ
 * `GET /faq?category=` و رکوردهای موجود)؛ رکوردهای جدید از `faq_category_id`
 * استفاده می‌کنند.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('faq_categories', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('slug')->unique();
            // زیرعنوان = خلاصه‌ی موضوعاتِ آن دسته؛ در منوی پشتیبانی زیر عنوان می‌آید
            $table->string('subtitle', 500)->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['is_active', 'sort_order'], 'faq_categories_active_sort_idx');
        });

        Schema::table('faqs', function (Blueprint $table) {
            $table->foreignId('faq_category_id')
                ->nullable()
                ->after('id')
                ->constrained('faq_categories')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('faqs', function (Blueprint $table) {
            $table->dropForeign(['faq_category_id']);
            $table->dropColumn('faq_category_id');
        });

        Schema::dropIfExists('faq_categories');
    }
};
