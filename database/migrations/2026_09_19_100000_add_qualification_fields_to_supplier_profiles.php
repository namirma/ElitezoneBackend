<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * درخواستِ احرازِ صلاحیتِ تأمین‌کننده — سایت فقط دروازه است، پنل محلِ کار
 * (`docs/specs/SUPPLIER_CONSOLIDATION_2026-09-19.md` §۴-۱).
 *
 * ستون‌های حقوقیِ شخصِ حقوقی/کارخانه‌ی ایرانی روی همان `supplier_profiles`
 * می‌نشینند (جدولِ تازه لازم نبود — یک پروفایل به ازای هر کاربر) و هر مدرک
 * یک `kind` می‌گیرد تا ادمین بداند «آگهی تأسیس» کدام فایل است.
 *
 * دو وضعیتِ تازه بدونِ تغییرِ ستون: `draft` (انتخابِ نقش، هنوز ارسال نشده —
 * در صفِ ادمین دیده نمی‌شود) و `needs_correction` (ادمین اصلاح خواسته).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supplier_profiles', function (Blueprint $table) {
            $table->string('entity_type', 20)->default('company')->after('company');
            $table->string('national_id', 11)->nullable()->after('entity_type');
            $table->string('registration_number', 20)->nullable()->after('national_id');
            $table->string('economic_code', 12)->nullable()->after('registration_number');
            $table->string('representative_name', 100)->nullable()->after('economic_code');
            $table->string('representative_national_code', 10)->nullable()->after('representative_name');
            $table->string('landline', 11)->nullable()->after('representative_national_code');
            $table->unsignedBigInteger('province_id')->nullable()->after('landline');
            $table->unsignedBigInteger('city_id')->nullable()->after('province_id');
            $table->string('factory_address', 500)->nullable()->after('city_id');
            $table->string('postal_code', 10)->nullable()->after('factory_address');
            $table->string('iban', 26)->nullable()->after('postal_code');
            $table->timestamp('submitted_at')->nullable()->after('rejection_reason');
            $table->timestamp('reviewed_at')->nullable()->after('submitted_at');
        });

        Schema::table('supplier_documents', function (Blueprint $table) {
            $table->string('kind', 30)->default('other')->after('title');
        });
    }

    public function down(): void
    {
        Schema::table('supplier_documents', function (Blueprint $table) {
            $table->dropColumn('kind');
        });

        Schema::table('supplier_profiles', function (Blueprint $table) {
            $table->dropColumn([
                'entity_type', 'national_id', 'registration_number', 'economic_code',
                'representative_name', 'representative_national_code', 'landline',
                'province_id', 'city_id', 'factory_address', 'postal_code', 'iban',
                'submitted_at', 'reviewed_at',
            ]);
        });
    }
};
