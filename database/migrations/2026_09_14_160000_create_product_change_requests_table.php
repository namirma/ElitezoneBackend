<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * درخواستِ تغییرِ کاتالوگ از سوی تأمین‌کننده — ساخت/ویرایشِ محصول یا واریانت.
 *
 * محصول و واریانت **مشترک**اند (هر تأمین‌کننده‌ای همان ردیف را می‌فروشد؛ فقط
 * قیمت و موجودی به کارخانه بسته‌اند)، پس نوشتنِ مستقیمِ تأمین‌کننده روی آن‌ها
 * ممنوع است. این جدول صف است: `payload` کلِ مشخصاتِ پیشنهادی را نگه می‌دارد،
 * `diff` برای ویرایش فقط فیلدهای تغییرکرده را (قبل/بعد)، و پذیرشِ ادمین همان
 * `payload` را از مسیرِ نوشتنِ خودِ ادمین (`ProductCatalogWriter`) اعمال می‌کند.
 *
 * جدولِ جدا از `supplier_requests` است چون آن جدول به سفارش/تیکت/قیمت گره
 * خورده و شکلِ `payload`ش تخت است؛ این‌جا payload تودرتو (واریانت‌ها، ویژگی‌ها،
 * واحدها) و دو کلیدِ خارجیِ کاتالوگ لازم است.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_change_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('type', 32);
            // nullOnDelete: حذفِ محصول نباید سابقه‌ی درخواست را پاک کند.
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained('product_variants')->nullOnDelete();
            $table->json('payload');
            $table->json('diff')->nullable();
            $table->text('note')->nullable();
            $table->string('status', 16)->default('pending');
            $table->foreignId('admin_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('admin_note')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();

            $table->index(['supplier_user_id', 'status']);
            $table->index(['status', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_change_requests');
    }
};
