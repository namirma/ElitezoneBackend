<?php

declare(strict_types=1);

use App\Models\Page;
use App\Models\PageBlock;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * فازِ ۳ استایلِ پویا — صفحه از «ثابت در کد» به «رکورد» تبدیل می‌شود.
 *
 * تا امروز سه صفحه‌ی بلوکی فقط `PageBlock::PAGES` بودند: یک آرایه‌ی ثابت در PHP
 * که **سه کپیِ دستی** در بک‌آفیس داشت (`PAGE_KEYS`، `PAGE_LABELS`،
 * `ROUTE_BY_PAGE`). یعنی افزودنِ یک صفحه چهار ویرایش در دو ریپو می‌خواست و هیچ
 * تستی آینه را نگه نمی‌داشت — همان شکلی که این پروژه بارها بابتش هزینه داده.
 *
 * سه ستون اضافه می‌شود:
 *   • `has_blocks` — این صفحه از بلوک ساخته می‌شود یا بدنه‌ی متنیِ ساده دارد
 *   • `style`      — اسلات‌های **سطحِ صفحه** (`blockGap`, `padTop`)، همان ویجتِ
 *                    `style` فازِ ۱ ولی در دامنه‌ی صفحه نه بلوک
 *   • `route`      — مسیرِ واقعیِ سایت. ⚠️ مسیر **همان اسلاگ نیست**:
 *                    `steel-kavir` روی `/steel-kavirCo` می‌نشیند. این دانش تا
 *                    امروز فقط در `PreviewPane.jsx` بک‌آفیس بود، پس پیش‌نمایشِ
 *                    پنل تنها جایی بود که می‌دانست صفحه واقعاً کجاست.
 *                    `null` یعنی «صفحه‌ی ساخته‌شده توسط ادمین» → `/p/{slug}`.
 *
 * مهاجرتِ داده: سه صفحه‌ی موجود **ساخته** می‌شوند، نه «روشن». آن‌ها هرگز ردیفِ
 * `pages` نداشتند؛ فقط ثابت بودند. بدونِ این بخش، `has_blocks` روی هیچ ردیفی
 * true نمی‌شد و سایت خالی می‌شد.
 */
return new class extends Migration
{
    /** عنوان و مسیرِ واقعیِ سه صفحه — از `PageComposerPage`/`PreviewPane` بک‌آفیس. */
    private const SEED = [
        PageBlock::PAGE_HOME => ['title' => 'صفحه اصلی', 'route' => '/'],
        PageBlock::PAGE_PODIUM => ['title' => 'پادیوم', 'route' => '/podium'],
        PageBlock::PAGE_STEEL_KAVIR => ['title' => 'فولاد کویر', 'route' => '/steel-kavirCo'],
    ];

    public function up(): void
    {
        // گاردِ `hasColumn` تزئینی نیست. DDLِ MySQL تراکنشی نیست، پس وقتی سیدِ
        // پایین در اولین اجرا شکست خورد (ستونِ `body` هنوز NOT NULL بود)، سه ستون
        // **ساخته شده** بودند ولی ردیفِ مهاجرت ثبت نشد — و اجرای بعدی با
        // «Duplicate column name» می‌مرد، یعنی یک مهاجرتِ نیمه‌اجراشده که فقط با
        // SQLِ دستی باز می‌شد. با این گارد اجرای دوباره خودش را جمع می‌کند.
        Schema::table(Page::TABLE, function (Blueprint $table) {
            if (! Schema::hasColumn(Page::TABLE, 'has_blocks')) {
                $table->boolean('has_blocks')->default(false)->after(Page::BODY);
            }
            if (! Schema::hasColumn(Page::TABLE, 'style')) {
                $table->json('style')->nullable()->after('has_blocks');
            }
            if (! Schema::hasColumn(Page::TABLE, 'route')) {
                $table->string('route', 191)->nullable()->after('style');
            }

            // `body` تا امروز NOT NULL بود چون هر صفحه‌ای متنی بود. صفحه‌ی بلوکی
            // واقعاً بدنه **ندارد** — محتوایش در `page_blocks` است — و سیدکردنِ
            // `''` یعنی ساختنِ مقداری که بعداً باید «یعنی خالی» تفسیر شود؛ همان
            // الگوی fallbackِ جعلی که «قاعده‌ی صداقت» منع می‌کند. NULL می‌شود.
            $table->text(Page::BODY)->nullable()->change();
        });

        foreach (self::SEED as $slug => $meta) {
            // `updateOrInsert` و نه `insert`: مهاجرت باید روی دیتابیسی که کسی
            // دستی این اسلاگ‌ها را ساخته هم بی‌خطر اجرا شود.
            DB::table(Page::TABLE)->updateOrInsert(
                [Page::SLUG => $slug],
                [
                    Page::TITLE => $meta['title'],
                    'route' => $meta['route'],
                    'has_blocks' => true,
                    Page::IS_ACTIVE => true,
                    Page::UPDATED_AT => now(),
                    Page::CREATED_AT => now(),
                ]
            );
        }
    }

    public function down(): void
    {
        // ردیف‌های سیدشده پاک **نمی‌شوند**: اگر ادمین بعداً بلوکی به آن‌ها بسته
        // باشد، حذفشان محتوای واقعی را یتیم می‌کند. فقط ستون‌ها برمی‌گردند.
        //
        // `body` هم به NOT NULL برنمی‌گردد: ردیف‌های بلوکی NULL دارند و برگرداندنِ
        // محدودیت یا شکست می‌خورد یا آن‌ها را به `''` له می‌کند.
        Schema::table(Page::TABLE, function (Blueprint $table) {
            $table->dropColumn(['has_blocks', 'style', 'route']);
        });
    }
};
