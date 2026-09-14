<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * لغوِ دوطرفه‌ی سفارش — تصمیم مدیریت ۲۰۲۶-۰۹-۰۹، پرسش ۴.
 *
 * «خریدار یا سوپلایر شروع می‌کند، ولی **توافق هر دو** لازم است. اگر یکی مخالف
 * بود → ارجاع به بک‌آفیس و تصمیم نهایی آن‌جا.» و مسیر مالی: «مهلت پاسخ ۴۸ ساعت
 * است. اگر رد شد دلیلش گفته می‌شود. اگر رد درخواست توسط بک‌آفیس پذیرفته شود،
 * پول از کیف پول تأمین‌کننده کسر و به کیف پول خریدار اضافه می‌شود و سفارش لغو
 * می‌گردد.»
 *
 * ## چرا جدولِ خودش و نه `supplier_requests`
 *
 * آن جدول یک‌طرفه است (تأمین‌کننده → بک‌آفیس) و جایی برای «تصمیمِ طرفِ مقابل»
 * ندارد. این جریان سه بازیگر دارد و هر تصمیم دلیل و زمانِ خودش را می‌خواهد:
 * درخواست‌کننده، طرفِ مقابل (تا `respond_until`)، و بک‌آفیس (فقط پس از ارجاع).
 *
 * ## دو پیش‌فرضی که مدیریت تعیین نکرده بود (سند ابهامات، بند ۴)
 *
 *   • مهلت از **لحظه‌ی ثبت درخواست** شمرده می‌شود، نه از اولین بازکردنِ بک‌آفیس.
 *   • پایانِ مهلت بدونِ پاسخ = **ارجاع به بک‌آفیس**، نه پذیرش/رد خودکار. پولی
 *     که هیچ آدمی درباره‌اش تصمیم نگرفته هرگز خودکار جابه‌جا نمی‌شود.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('order_cancel_requests')) {
            return;
        }

        Schema::create('order_cancel_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();

            // buyer | supplier — و خودِ کاربر، چون «خریدار» می‌تواند پرداخت‌کننده‌ی
            // «خرید برای دیگری» باشد و نامش از روی سفارش قابل بازسازی نیست.
            $table->string('requested_by', 10);
            $table->foreignId('requester_user_id')->constrained('users')->cascadeOnDelete();
            $table->text('reason');

            // مهلتِ پاسخِ طرفِ مقابل — از لحظه‌ی ثبت.
            $table->timestamp('respond_until');

            // accepted | rejected — پاسخِ طرفِ مقابل و دلیلش (رد بی‌دلیل پذیرفته نمی‌شود).
            $table->string('counterparty_decision', 10)->nullable();
            $table->text('counterparty_reason')->nullable();
            $table->timestamp('responded_at')->nullable();

            // لحظه‌ی رفتن به صفِ بک‌آفیس — با رد شدن، یا با پایانِ مهلت.
            $table->timestamp('escalated_at')->nullable();

            // approved | rejected — تصمیمِ نهاییِ بک‌آفیس، با دلیل.
            $table->string('admin_decision', 10)->nullable();
            $table->foreignId('admin_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('admin_reason')->nullable();
            $table->timestamp('decided_at')->nullable();

            // pending | escalated | approved | rejected
            $table->string('status', 20)->default('pending');

            $table->timestamps();

            $table->index(['order_id', 'status'], 'order_cancel_requests_order_status_idx');
            // کرونِ ارجاع دقیقاً همین دو را با هم فیلتر می‌کند.
            $table->index(['status', 'respond_until'], 'order_cancel_requests_status_deadline_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_cancel_requests');
    }
};
