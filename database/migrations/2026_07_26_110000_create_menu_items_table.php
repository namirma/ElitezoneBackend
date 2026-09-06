<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * منوهای سایت — یک سیستم واحد برای مگامنوی نوار بالا و ستون‌های لینکِ فوتر.
 *
 * تا امروز هر دو در کد هاردکد بودند: `NavMenu.jsx` (با ~۲۰ آیتمِ `action: ''`
 * یعنی لینکِ مرده) و `Footer.jsx` (`NAV_COLUMNS`). حالا هر دو از پنل مدیریت
 * می‌شوند و با `location` از هم جدا می‌مانند.
 *
 * سطح‌ها با `parent_id` مشخص می‌شوند (نه یک ستون level، تا جابه‌جایی آزاد بماند):
 *   parent_id = NULL            → آیتم اصلیِ نوار / عنوانِ ستونِ فوتر
 *   parent_id = یک آیتم اصلی    → سرستونِ مگامنو / لینکِ فوتر
 *   parent_id = یک سرستون       → گزینه‌ی داخل ستونِ مگامنو (فقط header)
 *
 * `url = NULL` یعنی «این آیتم فقط عنوان است، لینک نیست» — فرانت آن را به‌صورت
 * متنِ غیرقابل‌کلیک رندر می‌کند، نه یک لینکِ مرده.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('menu_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_id')
                ->nullable()
                ->constrained('menu_items')
                ->cascadeOnDelete();
            // جایگاه منو: header (مگامنوی بالا) | footer (ستون‌های لینکِ پایین)
            $table->string('location', 20)->default('header');
            $table->string('title');
            // مسیر داخلی (/rebar) یا آدرس کامل بیرونی؛ NULL = فقط عنوان
            $table->string('url')->nullable();
            // آیتم شاخص (مثل «خرید» که آبی و با آیکون فلش نمایش داده می‌شود)
            $table->boolean('is_highlighted')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['location', 'parent_id', 'is_active', 'sort_order'], 'menu_items_tree_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('menu_items');
    }
};
