<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * لغوِ لینکِ پرداخت (تصمیم مدیریت، گزینه‌ی ب — ۲۰۲۶-۰۹-۱۴).
 *
 * لغو، `beneficiary_user_id` را پاک می‌کند تا سبد باز شود؛ ولی صفحه‌ی پرداختِ
 * کارفرما باید بتواند بگوید «این سفارش توسط الیت‌یار لغو شد» نه «لینکی صادر
 * نشده». این ستون همان تفاوت را نگه می‌دارد و با صدورِ لینکِ بعدی پاک می‌شود.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('carts', function (Blueprint $table) {
            $table->timestamp('payment_link_cancelled_at')->nullable()->after('payment_link_sent_at');
        });
    }

    public function down(): void
    {
        Schema::table('carts', function (Blueprint $table) {
            $table->dropColumn('payment_link_cancelled_at');
        });
    }
};
