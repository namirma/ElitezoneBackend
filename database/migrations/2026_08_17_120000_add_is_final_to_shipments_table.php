<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `shipments.is_final` — ماشه‌ی تسویه‌ی نهایی (بند ۸.۲ نسخه‌ی ۲).
 *
 * تا فاز ۷ تسویه وقتی اجرا می‌شد که **همه‌ی** محموله‌های ساخته‌شده بارنامه
 * بگیرند. مشکل: تعداد محموله‌ها در لحظه‌ی ثبت سفارش تعیین می‌شود، ولی
 * تأمین‌کننده ممکن است بار را در تعداد دفعاتِ دیگری بفرستد — و مهم‌تر، در
 * کم‌تحویلی مجموع وزن هرگز به وزن سفارش نمی‌رسد. پس تأمین‌کننده باید بتواند
 * صریحاً بگوید «این آخرین محموله بود».
 *
 * سند دو ماشه تعریف می‌کند و هر دو پیاده شده‌اند: این پرچم، یا رسیدنِ مجموع
 * وزن‌ها به `weight_before_scale`.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('shipments', 'is_final')) {
            return;
        }

        Schema::table('shipments', function (Blueprint $table) {
            $table->boolean('is_final')->default(false)->after('waybill_ref')
                ->comment('تأمین‌کننده آخرین محموله را علامت می‌زند؛ ماشه‌ی تسویه‌ی نهایی');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('shipments', 'is_final')) {
            return;
        }

        Schema::table('shipments', function (Blueprint $table) {
            $table->dropColumn('is_final');
        });
    }
};
