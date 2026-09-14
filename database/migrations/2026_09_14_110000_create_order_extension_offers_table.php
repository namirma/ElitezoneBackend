<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * پیشنهادِ تمدیدِ رزرو — تصمیم مدیریت ۲۰۲۶-۰۹-۰۹، پرسش ۳.
 *
 * «رزروِ اول را هیچ‌کس نمی‌تواند کنسل کند. پس از پایانش، تمدید را تأمین‌کننده
 * پیشنهاد و خریدار می‌پذیرد یا رد می‌کند؛ ردِ هرکدام = کنسل. با توافق طرفین،
 * دفعات و مدت نامحدود.»
 *
 * پیش از این تمدید **یک‌طرفه** بود (`PUT /profile/orders/{order}/reserve-deadline`
 * مهلت را مستقیم جابه‌جا می‌کرد). حالا تمدید یک پیشنهاد است که تا پذیرشِ خریدار
 * هیچ اثری روی `orders.reserve_deadline` ندارد. یک ردیف به‌ازای هر پیشنهاد، چون
 * «دفعات نامحدود» یعنی تاریخچه، نه یک ستون روی سفارش.
 *
 * `proposed_until` مطلق ذخیره می‌شود (نه فقط ساعت): لحظه‌ی پذیرش ممکن است
 * ساعت‌ها بعد از پیشنهاد باشد و مهلتِ تازه باید همان چیزی باشد که خریدار دید.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('order_extension_offers')) {
            return;
        }

        Schema::create('order_extension_offers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->foreignId('supplier_user_id')->constrained('users')->cascadeOnDelete();

            // مدتِ پیشنهادی به ساعت (آنچه تأمین‌کننده تایپ کرد) و مهلتِ مطلقِ حاصل.
            $table->unsignedSmallInteger('hours');
            $table->timestamp('proposed_until');

            // pending | accepted | rejected | expired
            $table->string('status', 20)->default('pending');
            $table->timestamp('responded_at')->nullable();

            $table->timestamps();

            // فقط یک پیشنهادِ باز به‌ازای هر سفارش؛ کوئریِ Resource همین دو را می‌خواند.
            $table->index(['order_id', 'status'], 'order_extension_offers_order_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_extension_offers');
    }
};
