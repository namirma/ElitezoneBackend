<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * users.secret حالا هش bcrypt (۶۰ کاراکتر) نگه می‌دارد نه کد خام — ستون عریض می‌شود.
 * (OtpSecret — کد یکبارمصرف/توکن بازنشانی دیگر خام ذخیره نمی‌شود.)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('secret', 255)->nullable()->change();
        });
    }

    public function down(): void
    {
        // برنگرداندن طول — داده هش‌شده در ستون کوتاه‌تر جا نمی‌شود
    }
};
