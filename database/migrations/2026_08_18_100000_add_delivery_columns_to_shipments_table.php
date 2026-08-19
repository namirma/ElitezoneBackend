<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * چرخه‌ی تحویل روی محموله — فاز ۱۰، بند ۳.۱۰ و ۴.۵ سند.
 *
 * تا اینجا `shipments` بعد از ثبت بارنامه به `shipped` می‌رسید و همان‌جا
 * می‌ماند: هیچ ستونی نمی‌دانست راننده رسیده یا خریدار تحویل گرفته. وضعیت
 * `delivered` سفارش را فقط ادمین دستی می‌نوشت، و `partially_delivered` که در
 * فاز ۰ ساخته شد **هیچ نویسنده‌ای نداشت**.
 *
 * `delivery_deadline` جدا از `delivery_deadline_hours` ذخیره می‌شود چون
 * ساعت‌ها یک تنظیم است ولی مهلت یک لحظه‌ی محاسبه‌شده — همان تفکیکی که
 * `reserve_deadline` از `reserve_time` دارد، و به همان دلیل: تغییرِ بعدیِ
 * تنظیم نباید مهلتِ محموله‌ای که همین حالا در راه است را جابه‌جا کند.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            if (! Schema::hasColumn('shipments', 'arrived_at')) {
                $table->timestamp('arrived_at')->nullable()->after('registered_at')
                    ->comment('راننده (یا بک‌آفیس) رسیدن را ثبت کرد');
            }
            if (! Schema::hasColumn('shipments', 'arrival_source')) {
                $table->string('arrival_source', 20)->nullable()->after('arrived_at')
                    ->comment('driver_link | backoffice — بند ۳.۱۰: تماس تلفنی هم ثبت می‌شود');
            }
            if (! Schema::hasColumn('shipments', 'delivery_deadline_hours')) {
                $table->unsignedInteger('delivery_deadline_hours')->default(5)->after('arrival_source');
            }
            if (! Schema::hasColumn('shipments', 'delivery_deadline')) {
                $table->timestamp('delivery_deadline')->nullable()->after('delivery_deadline_hours');
            }
            if (! Schema::hasColumn('shipments', 'delivered_at')) {
                $table->timestamp('delivered_at')->nullable()->after('delivery_deadline');
            }
            if (! Schema::hasColumn('shipments', 'delivery_confirmed_by')) {
                $table->unsignedBigInteger('delivery_confirmed_by')->nullable()->after('delivered_at')
                    ->comment('کاربری که تأیید کرد؛ null یعنی از لینک امضاشده');
            }
            if (! Schema::hasColumn('shipments', 'returned_at')) {
                $table->timestamp('returned_at')->nullable()->after('delivery_confirmed_by');
            }
        });

        Schema::table('shipments', function (Blueprint $table) {
            $table->index('delivery_deadline', 'shipments_delivery_deadline_idx');
            $table->index(['order_id', 'status'], 'shipments_order_status_idx');
        });
    }

    public function down(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->dropIndex('shipments_delivery_deadline_idx');
            $table->dropIndex('shipments_order_status_idx');
        });

        Schema::table('shipments', function (Blueprint $table) {
            foreach ([
                'arrived_at', 'arrival_source', 'delivery_deadline_hours',
                'delivery_deadline', 'delivered_at', 'delivery_confirmed_by', 'returned_at',
            ] as $column) {
                if (Schema::hasColumn('shipments', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
