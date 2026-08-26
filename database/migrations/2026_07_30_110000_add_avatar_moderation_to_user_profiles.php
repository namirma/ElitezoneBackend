<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * صفِ تأییدِ تصویرِ ادمین‌ها.
 *
 * تصویرِ ادمین در تیکت‌های پشتیبانی کنارِ نامش به مشتری نشان داده می‌شود، پس عملاً
 * چهره‌ی برند است. اگر مثلِ کاربرِ عادی مستقیماً منتشر شود، یک ادمینِ زیرمجموعه
 * می‌تواند تصویری نامناسب بگذارد و تا وقتی کسی گزارش بدهد، همان تصویر روی هر
 * گفت‌وگوی پشتیبانی دیده می‌شود.
 *
 * راه‌حل: **ستونِ منتشرشده دست‌نخورده می‌ماند** و تصویرِ تازه در ستونِ جداگانه‌ای
 * می‌نشیند تا مدیر ارشد تأییدش کند. یعنی تا لحظه‌ی تأیید، چیزی که مشتری می‌بیند
 * همان تصویرِ قبلیِ تأییدشده است (یا تصویرِ پیش‌فرض) — نه تصویرِ در انتظار.
 *
 * چرا ستونِ جدا و نه یک ستونِ status روی همان avatar: با یک ستون، «در انتظار»
 * ناچار یعنی تصویرِ جدید همین حالا در دیتابیس نشسته و هر مصرف‌کننده‌ای که یادش
 * برود وضعیت را چک کند، آن را منتشر می‌کند. با دو ستون، انتشارِ تصویرِ تأییدنشده
 * مستلزمِ خواندنِ عمدیِ ستونِ pending است — حالتِ امن، پیش‌فرض می‌شود.
 *
 * کاربرانِ عادی از این جریان مستثنا هستند؛ تصویرشان مثلِ گذشته مستقیم منتشر
 * می‌شود (کنترلر تصمیم می‌گیرد، نه اسکیما).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_profiles', function (Blueprint $table) {
            if (! Schema::hasColumn('user_profiles', 'avatar_pending')) {
                $table->string('avatar_pending')->nullable()->after('avatar')
                    ->comment('تصویرِ آپلودشده‌ی در انتظارِ تأیید — هرگز مستقیم منتشر نمی‌شود');
            }

            if (! Schema::hasColumn('user_profiles', 'avatar_rejection_reason')) {
                $table->string('avatar_rejection_reason', 500)->nullable()->after('avatar_pending')
                    ->comment('دلیلِ ردِ آخرین تصویر — به خودِ ادمین نشان داده می‌شود');
            }

            if (! Schema::hasColumn('user_profiles', 'avatar_reviewed_at')) {
                $table->timestamp('avatar_reviewed_at')->nullable()->after('avatar_rejection_reason');
            }

            if (! Schema::hasColumn('user_profiles', 'avatar_reviewed_by')) {
                $table->unsignedBigInteger('avatar_reviewed_by')->nullable()->after('avatar_reviewed_at')
                    ->comment('شناسه‌ی مدیرِ ارشدی که تصمیم گرفت — برای ردِ ممیزی');
            }
        });
    }

    public function down(): void
    {
        Schema::table('user_profiles', function (Blueprint $table) {
            foreach (['avatar_pending', 'avatar_rejection_reason', 'avatar_reviewed_at', 'avatar_reviewed_by'] as $col) {
                if (Schema::hasColumn('user_profiles', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
