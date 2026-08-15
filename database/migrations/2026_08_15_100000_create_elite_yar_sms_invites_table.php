<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * دعوتِ پیامکیِ الیت یار توسط کارفرما — قرینه‌ی `employer_sms_invites`.
 *
 * نیمه‌ی گم‌شده‌ی جریانِ «دعوت الیت یارِ ثبت‌نام‌نکرده»: وقتی
 * `EliteYARRelationController::inviteEliteYAR` پاسخ `not_registered` می‌دهد،
 * فرانت اورلیِ دعوت با پیامک را باز می‌کند و اندپوینتی صدا می‌زند که وجود
 * نداشت (۴۰۴)، پس نیمه‌ی دوم جریان هرگز کار نمی‌کرد.
 *
 * یکتاییِ `(employer_id, mobile)` در سطح دیتابیس هم هست و نه فقط در
 * FormRequest: دو درخواستِ همزمان با یک شماره از قاعده‌ی `unique` ولیدیشن
 * هر دو رد می‌شوند ولی هر دو ردیف ساخته می‌شد و سهمیه‌ی ۱۰تایی را دوبار
 * می‌خورد.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('elite_yar_sms_invites')) {
            return;
        }

        Schema::create('elite_yar_sms_invites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employer_id')->constrained('users')->cascadeOnDelete();
            $table->string('mobile', 20);
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->unique(['employer_id', 'mobile'], 'elite_yar_sms_invites_employer_mobile_unique');
            $table->index('mobile', 'elite_yar_sms_invites_mobile_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('elite_yar_sms_invites');
    }
};
