<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * مهلتِ رزرو — بند ۳.۴ و ۴.۴، به‌علاوه‌ی الحاقیه‌ی ۱ (تغییر ۳).
 *
 * سه ستون، سه نقش متفاوت:
 *
 *   reserve_deadline           مهلتِ **جاری**. تمدید این را جلو می‌برد.
 *   reserve_deadline_original  مهلتِ لحظه‌ی رزرو. هرگز تغییر نمی‌کند.
 *   reserve_grace_hours        اسنپ‌شاتِ ارفاق در لحظه‌ی رزرو.
 *
 * چرا `reserve_deadline_original` جدا نگه داشته می‌شود: پنجره‌ی لغوِ خریدار
 * دقیقاً **بینِ این دو** است (بند ۳.۴). خریدار تا مهلتِ اولیه نمی‌تواند لغو کند
 * (کالا برایش کنار گذاشته شده)، ولی اگر تأمین‌کننده مهلت را تمدید کرد، آن بازه‌ی
 * اضافه چیزی است که خریدار نخواسته بود — پس در آن بازه حق خروج دارد. با یک
 * ستون، «چقدر تمدید شده» قابل بازسازی نیست.
 *
 * چرا `reserve_grace_hours` اسنپ‌شات است (الحاقیه‌ی ۱): اگر تأمین‌کننده پس از
 * ثبتِ یک رزرو مقدارِ ارفاق را عوض کند، مهلتِ آن رزرو نباید بی‌صدا جابه‌جا شود.
 * همان انضباطی که کد از قبل برای `vat_rate` و نرخ‌های کمیسیون دارد.
 *
 * `reservation_expires_at` دست‌نخورده می‌ماند و در فاز پاک‌سازی (۱۴) حذف
 * می‌شود — مهاجرت‌ها افزایشی‌اند.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (! Schema::hasColumn('orders', 'reserve_deadline')) {
                $table->timestamp('reserve_deadline')->nullable()->after('reservation_expires_at')
                    ->comment('مهلت جاری رزرو؛ تمدید این را جلو می‌برد');
            }
            if (! Schema::hasColumn('orders', 'reserve_deadline_original')) {
                $table->timestamp('reserve_deadline_original')->nullable()->after('reserve_deadline')
                    ->comment('مهلت لحظه رزرو؛ هرگز تغییر نمی‌کند — مبنای پنجره لغو خریدار');
            }
            if (! Schema::hasColumn('orders', 'reserve_grace_hours')) {
                $table->unsignedInteger('reserve_grace_hours')->nullable()->after('reserve_deadline_original')
                    ->comment('اسنپ‌شات مهلت ارفاق در لحظه رزرو');
            }
        });

        Schema::table('orders', function (Blueprint $table) {
            if (! $this->hasIndex('orders', 'orders_reserve_deadline_idx')) {
                $table->index('reserve_deadline', 'orders_reserve_deadline_idx');
            }
        });

        // رزروهای زنده‌ی موجود مهلتِ تازه را از همان مقدارِ قدیمی می‌گیرند تا
        // کرونِ انقضا آن‌ها را با `reserve_deadline = null` نادیده نگیرد و برای
        // همیشه معلق نمانند.
        DB::table('orders')
            ->where('status', 'reserved')
            ->whereNull('reserve_deadline')
            ->whereNotNull('reservation_expires_at')
            ->update([
                'reserve_deadline' => DB::raw('reservation_expires_at'),
                'reserve_deadline_original' => DB::raw('reservation_expires_at'),
            ]);
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if ($this->hasIndex('orders', 'orders_reserve_deadline_idx')) {
                $table->dropIndex('orders_reserve_deadline_idx');
            }
        });

        Schema::table('orders', function (Blueprint $table) {
            foreach (['reserve_deadline', 'reserve_deadline_original', 'reserve_grace_hours'] as $column) {
                if (Schema::hasColumn('orders', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }

    private function hasIndex(string $table, string $index): bool
    {
        return DB::select('SHOW INDEX FROM `'.$table.'` WHERE Key_name = ?', [$index]) !== [];
    }
};
