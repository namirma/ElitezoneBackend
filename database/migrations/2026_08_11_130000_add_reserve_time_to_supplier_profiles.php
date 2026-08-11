<?php

use App\Models\SupplierProfile;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * اعتبارِ قیمتِ تأمین‌کننده — بند ۲.۲، ۳.۲ و ۴.۳ سند بازطراحی.
 *
 * `reserve_time` یک datetime مطلق است: تا آن لحظه قیمت‌های این تأمین‌کننده
 * تغییر نمی‌کنند. با گذشتنش و نبودِ قیمت جدید، محصولاتش از فروش خارج می‌شوند.
 *
 * ── چرا ردیف‌های موجود backfill می‌شوند ──
 *
 * بند ۴.۳ می‌گوید «null یعنی محصولات از فروش خارج‌اند». اگر این ستون با مقدارِ
 * null به تأمین‌کننده‌های موجود اضافه شود، در همان لحظه‌ی مهاجرت **کلِ کاتالوگ
 * خاموش می‌شود** و تا وقتی تک‌تکِ تأمین‌کننده‌ها وارد پنل شوند و تاریخ بگذارند
 * سایت چیزی برای فروش ندارد. این یک قطعیِ خودخواسته است، نه قاعده‌ی کسب‌وکار.
 *
 * پس تأمین‌کننده‌های **تأییدشده‌ی موجود** یک پنجره‌ی گذارِ هفت‌روزه می‌گیرند تا
 * فرصت داشته باشند مقدارِ واقعیِ خودشان را ثبت کنند. از آن پس قاعده کامل اعمال
 * می‌شود: هر تأمین‌کننده‌ی تازه با null شروع می‌کند و تا تعیینِ `reserve_time`
 * محصولش فروخته نمی‌شود — که همان چیزی است که سند می‌خواهد.
 *
 * هفت روز عدد مقدسی نیست؛ فقط باید از «تا وقتی تیم به تأمین‌کننده‌ها خبر بدهد»
 * بلندتر باشد. این یک مقدارِ یک‌بارمصرفِ گذار است و جایی خوانده نمی‌شود.
 */
return new class extends Migration
{
    private const TRANSITION_WINDOW_DAYS = 7;

    public function up(): void
    {
        Schema::table('supplier_profiles', function (Blueprint $table) {
            if (! Schema::hasColumn('supplier_profiles', 'reserve_time')) {
                $table->timestamp('reserve_time')->nullable()->after('status')
                    ->comment('پایان اعتبار قیمت‌ها؛ null یعنی محصولات از فروش خارج‌اند');
            }
            if (! Schema::hasColumn('supplier_profiles', 'reserve_auto_extend_minutes')) {
                $table->unsignedInteger('reserve_auto_extend_minutes')->default(0)->after('reserve_time')
                    ->comment('تمدید خودکار برای رزروِ دیرهنگام، به دقیقه');
            }
        });

        $backfilled = DB::table('supplier_profiles')
            ->where('status', SupplierProfile::STATUS_APPROVED)
            ->whereNull('reserve_time')
            ->update(['reserve_time' => now()->addDays(self::TRANSITION_WINDOW_DAYS)]);

        if ($backfilled > 0) {
            Log::info(
                "add_reserve_time_to_supplier_profiles: {$backfilled} approved supplier(s) given a "
                .self::TRANSITION_WINDOW_DAYS.'-day transition window. They must set a real reserve_time before it lapses.'
            );
        }
    }

    public function down(): void
    {
        Schema::table('supplier_profiles', function (Blueprint $table) {
            foreach (['reserve_time', 'reserve_auto_extend_minutes'] as $column) {
                if (Schema::hasColumn('supplier_profiles', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
