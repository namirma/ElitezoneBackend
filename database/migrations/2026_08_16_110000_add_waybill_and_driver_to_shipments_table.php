<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * بارنامه، راننده و وزن باسکول — روی **محموله**، نه سفارش (فاز ۷، بند ۳.۷).
 *
 * یک سفارش می‌تواند چند محموله داشته باشد (چند مبدأ → چند مقصد؛ کلِ زیرسیستم
 * `shipments` برای همین ساخته شده). هر محموله بارنامه، راننده و خودروی خودش را
 * دارد، پس این ستون‌ها اینجا می‌نشینند و نه روی `orders`.
 *
 * `orders.waybill_ref` حذف نمی‌شود: پس از ثبتِ همه‌ی محموله‌ها با فهرستِ شماره‌ها
 * پر می‌شود تا `OrderTimelineService` و فیلترِ اسنادِ ادمین همان‌طور کار کنند.
 *
 * `actual_weight` وزنِ باسکولِ همین لِگ است. در فاز ۷ تسویه هنوز سطحِ سفارش است
 * و از مجموعِ این ستون‌ها تغذیه می‌شود؛ تسویه‌ی per-shipment و شاخه‌ی اضافه‌بار
 * کارِ فاز ۸ است.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->string('waybill_ref')->nullable()->after('status');

            $table->string('driver_name')->nullable()->after('waybill_ref');
            $table->string('driver_national_code', 10)->nullable()->after('driver_name');
            $table->string('driver_mobile', 15)->nullable()->after('driver_national_code');

            $table->string('vehicle_plate', 30)->nullable()->after('driver_mobile');
            $table->string('vehicle_type')->nullable()->after('vehicle_plate');

            $table->decimal('actual_weight', 12, 3)->nullable()->after('vehicle_type');
            $table->timestamp('registered_at')->nullable()->after('actual_weight');

            // «کدام محموله‌ها هنوز ثبت نشده‌اند» پرسشِ داغِ این فاز است:
            // `RegisterShipmentAction` بعد از هر ثبت آن را می‌پرسد تا بفهمد
            // سفارش کامل شده یا نه.
            $table->index(['order_id', 'registered_at'], 'shipments_order_registered_idx');
        });
    }

    public function down(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->dropIndex('shipments_order_registered_idx');
            $table->dropColumn([
                'waybill_ref', 'driver_name', 'driver_national_code', 'driver_mobile',
                'vehicle_plate', 'vehicle_type', 'actual_weight', 'registered_at',
            ]);
        });
    }
};
