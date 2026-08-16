<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * «چه کسی پول داد» را از «چه کسی سفارش را ثبت کرد» جدا می‌کند — فاز ۶.
 *
 * تا امروز کیف پولِ خریدار (WO) همیشه به `placer_user_id` بسته می‌شد. در مسیرِ
 * «الیت‌یار برای کارفرما سفارش می‌دهد» — که `StoreOrderRequest` اجباری‌اش
 * می‌کند — این یعنی پولِ کالای کارفرما از کیف پولِ **خودِ الیت‌یار** کسر
 * می‌شد، در حالی که همان شخص روی همان سفارش کمیسیون هم می‌گرفت. تصمیم مدیریت
 * (پرسش ۳، گزینه‌ی الف): پرداخت همیشه از کیف پول کارفرما.
 *
 * ⚠️ **بک‌فیل عمداً `placer_user_id` است و نه `beneficiary_user_id`.**
 * سفارش‌های قدیمی واقعاً توسط ثبت‌کننده پرداخت شده‌اند. اگر ردیف‌های گذشته را
 * به ذی‌نفع می‌بستیم، مسیرِ استرداد و `financial:doctor` برای همه‌ی آن‌ها به
 * کیف پولِ اشتباه اشاره می‌کرد — یعنی یک تغییرِ رفتاری که به گذشته هم سرایت
 * می‌کند. این ستون فقط برای سفارش‌های تازه معنا دارد.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('payer_user_id')
                ->nullable()
                ->after('placer_user_id')
                ->constrained('users')
                ->nullOnDelete();
        });

        // تاریخ را همان‌طور که بود نگه می‌داریم: پرداخت‌کننده‌ی هر سفارشِ موجود
        // همان ثبت‌کننده‌اش بوده است.
        DB::table('orders')->update(['payer_user_id' => DB::raw('placer_user_id')]);
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropForeign(['payer_user_id']);
            $table->dropColumn('payer_user_id');
        });
    }
};
