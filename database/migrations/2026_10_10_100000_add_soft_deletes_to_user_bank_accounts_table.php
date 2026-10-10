<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * حذفِ حسابِ بانکی فقط از بک‌آفیس، و نرم (علی ۱۴۰۵/۰۷/۱۹).
 *
 * برداشت‌ها `meta.bank_account_id` را نگه می‌دارند و ردِ ممیزیِ مدل هم به همین ردیف
 * اشاره می‌کند؛ حذفِ سخت آن ارجاع‌ها را بی‌صاحب می‌کرد. فقط افزودنی.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_bank_accounts', function (Blueprint $table) {
            $table->softDeletes();
        });
    }

    /**
     * عمداً خالی: برداشتنِ ستون، حساب‌هایی را که بک‌آفیس حذف کرده دوباره زنده و
     * قابلِ برداشت می‌کرد — بدتر از ماندنِ یک ستونِ nullable.
     */
    public function down(): void {}
};
