<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * دفترچه‌ی رانندگانِ هر حمل‌کننده — بند ۴.۶ و ۳.۹ سند.
 *
 * پیش از این، مشخصات راننده denormalize روی `shipments` می‌نشست (نام، موبایل،
 * کد ملی، پلاک، نوع خودرو). برای یک بارنامه کافی بود، ولی سه چیزی را که سند
 * می‌خواهد نمی‌داد: «رانندگان در جدول می‌مانند و برای سفارش‌های بعدی قابل
 * انتخاب‌اند»، «موبایل با OTP احراز می‌شود»، و ویرایش/غیرفعال‌سازی از پنل.
 *
 * ستون‌های `shipments` **می‌مانند و حذف نمی‌شوند**: آن‌ها اسنپ‌شاتِ لحظه‌ی حملند
 * و باید حتی پس از حذف یا ویرایشِ راننده همان چیزی را نشان دهند که روی بارنامه
 * بوده — همان انضباطی که `abandoned_carts.items` و نرخ‌های اسنپ‌شات‌شده دارند.
 * `shipments.driver_id` فقط پیوندِ نرم به دفترچه است.
 *
 * راننده **حساب کاربری ندارد** (بند ۳.۹): نه ردیفی در `users`، نه رمزی. ارتباط
 * فقط پیامک و لینک امضاشده است — به همین دلیل کلید یکتا `carrier + mobile` است
 * و نه چیزی سراسری.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('drivers')) {
            Schema::create('drivers', function (Blueprint $t) {
                $t->id();
                $t->foreignId('carrier_user_id')->constrained('users')->cascadeOnDelete();
                $t->string('full_name');
                $t->string('mobile', 20);
                $t->string('national_code', 10)->nullable();
                $t->string('plate_number', 30)->nullable();
                $t->string('vehicle_type', 60)->nullable();
                $t->timestamp('mobile_verified_at')->nullable();
                $t->boolean('is_active')->default(true);
                $t->timestamps();

                // یکتایی per-حمل‌کننده و نه سراسری: یک راننده می‌تواند برای چند
                // شرکت کار کند و هر کدام رکورد خودشان را دارند.
                $t->unique(['carrier_user_id', 'mobile'], 'drivers_carrier_mobile_unique');
            });
        }

        if (! Schema::hasColumn('shipments', 'driver_id')) {
            Schema::table('shipments', function (Blueprint $t) {
                // nullable و بدون FK سخت: بارنامه‌های پیش از این جدول
                // `driver_id` ندارند ولی اسنپ‌شاتِ نام و موبایلشان معتبر است.
                $t->unsignedBigInteger('driver_id')->nullable()->after('vehicle_type');
                $t->index('driver_id', 'shipments_driver_id_idx');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('shipments', 'driver_id')) {
            Schema::table('shipments', function (Blueprint $t) {
                $t->dropIndex('shipments_driver_id_idx');
                $t->dropColumn('driver_id');
            });
        }

        Schema::dropIfExists('drivers');
    }
};
