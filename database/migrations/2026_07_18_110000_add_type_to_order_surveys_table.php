<?php

use App\Models\OrderSurvey;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * نظرسنجی دو نوعه طبق سند طراحی (پروسه خرید):
 *   S = نظرسنجی از خریدار (placer / beneficiary)
 *   T = نظرسنجی از الیت یار (elite_yar_user_id — صاحب کمیسیون)
 *
 * قبلاً اولین ثبت (از هر طرفی) فلگ سطح-سفارش SURVEY_COMPLETED را می‌زد و طرف
 * دیگر بلاک می‌شد؛ با ستون type هر دو نظرسنجی مستقل و هرکدام یک‌بار ثبت می‌شوند.
 * ردیف‌های موجود همگی نظرسنجی خریدار بودند → backfill به 'buyer'.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_surveys', function (Blueprint $table) {
            $table->string('type', 20)
                ->default(OrderSurvey::TYPE_BUYER)
                ->after('user_id')
                ->comment('S=buyer | T=elite_yar');

            $table->unique(['order_id', 'type'], 'order_surveys_order_type_unique');
        });
    }

    public function down(): void
    {
        Schema::table('order_surveys', function (Blueprint $table) {
            $table->dropUnique('order_surveys_order_type_unique');
            $table->dropColumn('type');
        });
    }
};
