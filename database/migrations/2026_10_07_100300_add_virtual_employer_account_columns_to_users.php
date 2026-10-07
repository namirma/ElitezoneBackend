<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * مدیریت، دورِ هفتم، پرسشِ ۷-۵ (۱۴۰۵/۰۷/۱۵): «حسابِ مجازیِ کارفرما» —
 * `docs/session/MANAGEMENT_ANSWERS_2026-10-07_ROUND7.md`.
 *
 * فقط دو ستونِ وضعیت؛ خودِ حساب همان ردیفِ عادیِ `users` است، چون «رفتار سیستم نسبت به این حساب دقیقا مثل یک حساب
 * عادی است». هیچ ردیفِ موجودی دست نمی‌خورد (NULL = حسابی که صاحبش خودش ساخت).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('created_by_elite_yar_id')->nullable()->after('type_id')
                ->constrained('users')->restrictOnDelete()
                ->comment('الیت‌یاری که حسابِ مجازیِ کارفرما را باز کرد (مدیریت، دورِ هفتم، ۷-۵)');
            $table->timestamp('account_claimed_at')->nullable()->after('created_by_elite_yar_id')
                ->comment('کارفرما توافق‌نامه‌ها را پذیرفت و حسابِ مجازی واقعی شد');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('created_by_elite_yar_id');
            $table->dropColumn('account_claimed_at');
        });
    }
};
