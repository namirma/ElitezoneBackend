<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * اسنپ‌شاتِ نرخِ کرایه روی هر محموله — همان الگوی `orders.commission_rate`.
 *
 * تا پیش از این `WeighbridgeReconciler` کرایه را در لحظه‌ی باسکول از جدولِ **زنده‌ی**
 * `shipping_rates` دوباره قیمت می‌کرد. پس ویرایشِ نرخِ یک مسیر بین ثبتِ سفارش و
 * باسکول (یا واردکردنِ نرخ برای سفارشی که با کرایه‌ی صفر ثبت شده بود) سفارشی با
 * وزنِ **دقیقاً برابر** را `surplus` می‌کرد و بدونِ هیچ رضایتی از خریدار پول
 * می‌گرفت — گاردِ رضایتِ دوطرفه فقط وزن را نگاه می‌کند.
 *
 * ‏`cost_basis` از قبل روی محموله بود؛ این دو ستون مؤلفه‌های عددیِ همان نرخ‌اند:
 *   - `rate_flat_cost` — هزینه‌ی ثابتِ مسیر
 *   - `rate_unit_cost` — هزینه‌ی واحد برای **همان مبنای انتخاب‌شده** (کیلوگرم یا تعداد)
 *
 * ‏nullable عمداً: `null` یعنی «محموله پیش از این مهاجرت ثبت شده و اسنپ‌شات ندارد»
 * و reconciler آن را با مسیرِ سازگاری (مقیاس‌کردنِ کرایه‌ی ثبت‌شده) می‌سنجد. صفر
 * یعنی «نرخی پیدا نشد و کرایه صفر ثبت شد» — و صفر هم باید صفر بماند.
 *
 * ‏`decimal(20,0)` — ریالِ صحیح، مثل بقیه‌ی ستون‌های مبلغ
 * (`MoneyColumnsAreIntegerRialsTest`).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('shipments', 'rate_flat_cost')) {
            return;
        }

        Schema::table('shipments', function (Blueprint $t) {
            $t->decimal('rate_flat_cost', 20, 0)->nullable()->after('shipping_cost');
            $t->decimal('rate_unit_cost', 20, 0)->nullable()->after('rate_flat_cost');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('shipments', 'rate_flat_cost')) {
            return;
        }

        Schema::table('shipments', function (Blueprint $t) {
            $t->dropColumn(['rate_flat_cost', 'rate_unit_cost']);
        });
    }
};
