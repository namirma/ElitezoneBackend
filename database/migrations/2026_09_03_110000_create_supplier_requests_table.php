<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * صفِ درخواست‌های تأمین‌کننده — لغوِ سفارش، و افزودنِ محصول/واریانت.
 *
 * ## چرا «درخواست» و نه انجامِ مستقیم
 *
 * **لغو:** بند ۵.۳ سند، لغوِ اجباری را *تنها* اختیارِ وضعیتیِ ادمین کرده و بند
 * ۳.۶ آن را به «تیکت + دلیل» مشروط می‌کند. یک لغوِ خودسرویسِ تأمین‌کننده هر دو
 * را دور می‌زد — و آن اقدام سفارشِ پرداخت‌شده را برمی‌گرداند، موجودی را آزاد
 * می‌کند و کیف‌پول‌ها را عقب می‌زند. پس تأمین‌کننده **درخواست** می‌دهد و
 * `AdminOrderController::forceCancel` همان مسیرِ همیشگی می‌ماند.
 *
 * **محصول:** کاتالوگ عمداً ادمین‌محور است (`SupplierProductPriceController` هم
 * همین را در docblock خودش می‌گوید). نوشتنِ مستقیمِ تأمین‌کننده در
 * `products`/`product_variants` یعنی هر کس نامِ کالای خودش را بسازد و کاتالوگ
 * از هم بپاشد.
 *
 * یک جدول برای هر دو، چون چرخه‌شان یکی است: ثبت → بررسی → پذیرش/رد با دلیل.
 * `payload` جزئیاتِ نوعِ درخواست را نگه می‌دارد؛ ستونِ اختصاصی به‌ازای هر نوع
 * یعنی جدولی که با هر نوعِ تازه عوض می‌شود.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('supplier_requests')) {
            return;
        }

        Schema::create('supplier_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_user_id')->constrained('users')->cascadeOnDelete();

            // `order_cancellation` | `catalog_addition`
            $table->string('type', 40);

            // فقط برای لغو — `nullOnDelete` چون درخواست باید در سابقه بماند
            // حتی اگر سفارش بعداً پاک شود.
            $table->foreignId('order_id')->nullable()->constrained('orders')->nullOnDelete();

            // تیکتی که همراهِ درخواست ساخته می‌شود؛ مسیرِ گفت‌وگو همان تیکت است.
            $table->foreignId('ticket_id')->nullable()->constrained('tickets')->nullOnDelete();

            $table->text('reason');
            $table->json('payload')->nullable();

            // pending | approved | rejected
            $table->string('status', 20)->default('pending');
            $table->text('review_note')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();

            $table->timestamps();

            $table->index(['supplier_user_id', 'status'], 'supplier_requests_owner_status_idx');
            $table->index(['type', 'status'], 'supplier_requests_type_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_requests');
    }
};
