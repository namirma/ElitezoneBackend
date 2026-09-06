<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * دو شکافِ چرخه‌ی پذیرشِ تأمین‌کننده.
 *
 * ## ۱. `supplier_profiles.rejection_reason`
 *
 * ادمین می‌توانست رد کند ولی هیچ‌جا نمی‌توانست بگوید **چرا**. متقاضی فقط
 * `status: rejected` می‌دید و نمی‌دانست چه چیزی را اصلاح کند — و حالا که
 * ویرایشِ پروفایل او را به صف برمی‌گرداند، این نیمه‌کاره‌بودن آزاردهنده‌تر هم
 * هست: راهِ بازگشت باز شده ولی مقصد معلوم نیست.
 *
 * ## ۲. جدولِ `supplier_documents`
 *
 * فرمِ درخواست فقط متن می‌گرفت — `standards` یک رشته‌ی آزاد بود که ادمین
 * قرار بود «تأییدش» کند، بدونِ اینکه هیچ مدرکی برای دیدن داشته باشد. پروانه‌ی
 * کسب، گواهیِ استاندارد و کارتِ ملی هیچ‌کدام جایی برای آپلود نداشتند.
 *
 * فایل روی دیسکِ خصوصیِ `local` می‌نشیند (نه `public`)، دقیقاً مثل مدارکِ
 * سوابقِ شغلی: مدرکِ هویتی/حقوقی نباید با حدسِ نامِ فایل از اینترنت خوانده شود.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supplier_profiles', function (Blueprint $table) {
            if (! Schema::hasColumn('supplier_profiles', 'rejection_reason')) {
                $table->text('rejection_reason')->nullable()->after('status')
                    ->comment('دلیلِ ردِ ادمین؛ با درخواستِ دوباره پاک می‌شود');
            }
        });

        if (! Schema::hasTable('supplier_documents')) {
            Schema::create('supplier_documents', function (Blueprint $table) {
                $table->id();
                $table->foreignId('supplier_profile_id')
                    ->constrained('supplier_profiles')
                    ->cascadeOnDelete();
                $table->string('title', 150);
                $table->string('path');
                $table->string('original_name', 255);
                $table->string('mime_type', 100)->nullable();
                $table->unsignedBigInteger('size')->nullable();
                $table->timestamps();

                $table->index('supplier_profile_id', 'supplier_documents_profile_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_documents');

        Schema::table('supplier_profiles', function (Blueprint $table) {
            if (Schema::hasColumn('supplier_profiles', 'rejection_reason')) {
                $table->dropColumn('rejection_reason');
            }
        });
    }
};
