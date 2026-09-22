<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * عضویتِ سازه ۹۰ به سطحِ کاربر می‌آید.
 *
 * تا امروز فقط روی `engineer_profiles.is_saze90_user` بود، و آن ردیف فقط وقتی
 * ساخته می‌شود که کاربر مدرک بفرستد (`POST /profile/engineer`). با تصمیمِ
 * مدیریت (۱۴۰۵/۰۶/۳۰) تأیید در لانچ **فقط** از راهِ سازه ۹۰ است — یعنی
 * الیت‌یارِ عادی هرگز ردیفِ مهندس نمی‌سازد و جایی برای نگه‌داشتنِ عضویتش
 * نداشت؛ پیمانکارها هم اصلاً ستونی نداشتند.
 *
 * ⚠️ عمداً **بدونِ backfill** از ستونِ قدیمی: خواننده‌ی پیش از ۱۴۰۵/۰۶/۳۰ پاسخِ
 * «No user found.» سازه ۹۰ را کدِ نشان می‌خواند و هر شماره‌ی ناشناس را «عضو» ثبت
 * کرده بود (`BadgeSyncTest::an_unknown_mobile_is_not_a_member`) — کپی‌کردنِ آن
 * پرچم یعنی همان خطا را به حقیقتِ تأییدِ لانچ ارتقا دادن. ستون از false شروع
 * می‌شود و `SyncUserBadgeAction` روی اولین خواندنِ پروفایلِ هر کاربر (که سایت در
 * هر بوت انجام می‌دهد) مقدارِ درست را با خواننده‌ی اصلاح‌شده می‌نویسد.
 *
 * ستونِ قدیمی می‌ماند و همچنان نوشته می‌شود (قانون یک)؛ محمولِ
 * `User::isVerifiedEliteYAR()` فقط این یکی را می‌خواند.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // نامِ ستون literal است تا Larastan آن را از مایگریشن بخواند (ثابت را نمی‌بیند).
            $table->boolean('is_saze90_user')->default(false)->after(User::BADGE_ID);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('is_saze90_user');
        });
    }
};
