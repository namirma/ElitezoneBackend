<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * مبنای آستانه‌ی تأیید قیمت: «آخرین قیمتِ تأییدشده» (تصمیم مدیریت ۲۰۲۶-۰۹-۰۹).
 *
 * ⚠️ مدیریت گزینه‌ی «قیمت فعلی» را **دید و ردش کرد** و «آخرین قیمت تأییدشده» را
 * انتخاب کرد. این دو یکی نیستند و تفاوتشان یک سوراخِ واقعی است:
 *
 *   تغییرِ زیرِ آستانه بدونِ تأیید اعمال می‌شود. پس با مبنای «قیمت فعلی»،
 *   تأمین‌کننده می‌تواند ۱۴٪ + ۱۴٪ + ۱۴٪ بزند و قیمت را ۴۸٪ جابه‌جا کند بدون
 *   اینکه حتی یک بار به صفِ تأیید بخورد. هر قدم قانونی است، جمعشان نیست.
 *
 * این ستون‌ها آن مبنا را نگه می‌دارند: فقط وقتی جابه‌جا می‌شوند که **ادمین تأیید
 * کند** (یا وقتی ردیف ساخته می‌شود). تغییرِ کوچکِ فوری مبنا را تکان نمی‌دهد —
 * دقیقاً همان چیزی که راهِ سالامی را می‌بندد.
 *
 * backfill با قیمتِ فعلی است، نه NULL: ردیف‌های موجود هرگز تأیید نشده‌اند، و
 * گرفتنِ NULL یعنی «مبنا ندارد» که باید در کد شاخه‌ی جدا می‌گرفت. مقدارِ امروز
 * دقیقاً همان چیزی است که تا این لحظه پذیرفته شده بوده.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_prices', function (Blueprint $table) {
            $table->decimal('approved_general_price', 15, 2)->nullable()->after('golden_figure');
            $table->decimal('approved_special_price', 15, 2)->nullable()->after('approved_general_price');
            $table->decimal('approved_golden_figure', 15, 2)->nullable()->after('approved_special_price');
            $table->timestamp('approved_price_at')->nullable()->after('approved_golden_figure');
        });

        DB::table('product_prices')->update([
            'approved_general_price' => DB::raw('general_price'),
            'approved_special_price' => DB::raw('special_price'),
            'approved_golden_figure' => DB::raw('golden_figure'),
        ]);
    }

    public function down(): void
    {
        Schema::table('product_prices', function (Blueprint $table) {
            $table->dropColumn([
                'approved_general_price',
                'approved_special_price',
                'approved_golden_figure',
                'approved_price_at',
            ]);
        });
    }
};
