<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * منبعِ وزنِ هر محموله — بند ۴.۵ سند (`weight_source`).
 *
 * بدون این ستون، `actual_weight` می‌گفت وزن **چقدر** بوده ولی نه از **کجا**
 * آمده. تفاوتش ممیزی‌پذیری است: وزنی که از سامانه‌ی باسکول خوانده شده با وزنی
 * که اپراتور تایپ کرده اعتبار یکسان ندارند، و وقتی یک تعدیلِ مالی بعداً زیر
 * سؤال برود این تنها چیزی است که جوابش را می‌دهد.
 *
 * nullable چون بارنامه‌های پیش از این مهاجرت منبعشان ثبت نشده؛ `null` یعنی
 * «نامعلوم» و `WeightSourceResolver` آن را به `manual` (رفتار قبلی) می‌گیرد،
 * نه اینکه به‌دروغ ادعای منبعی کند که نداشته.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('shipments', 'weight_source')) {
            return;
        }

        Schema::table('shipments', function (Blueprint $t) {
            $t->string('weight_source', 20)->nullable()->after('actual_weight')
                ->comment('api | ocr | manual — بند ۳.۷');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('shipments', 'weight_source')) {
            return;
        }

        Schema::table('shipments', function (Blueprint $t) {
            $t->dropColumn('weight_source');
        });
    }
};
