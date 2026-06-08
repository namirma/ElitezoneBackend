<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * جدول ثبت واریزهای پادیوم — برای idempotency و پیگیری
 *
 * هر بار که Job polling اجرا می‌شود، شماره سند (DocNumber) یکتای بانک
 * را بررسی می‌کند؛ اگر قبلاً ثبت شده بود، از پردازش مجدد جلوگیری می‌کند.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('podium_deposit_records', function (Blueprint $table) {
            $table->id();

            // کلید idempotency — شماره سند یکتای بانک
            $table->string('doc_number', 64)->unique()->comment('شماره سند بانکی — کلید idempotency');

            // شناسه واریز که کاربر در انتقال استفاده کرده
            $table->string('shenaseh_variz', 30)->nullable()->comment('شناسه واریز (TransferMoneyBillNumber)');

            // کد ملی استخراج‌شده از شناسه واریز
            $table->string('national_code', 10)->nullable()->comment('کد ملی مستخرج از شناسه واریز');

            // مبلغ واریز (به ریال)
            $table->unsignedBigInteger('creditor_amount')->default(0)->comment('مبلغ بستانکار (ریال)');

            // کاربر مرتبط (nullable — اگر کد ملی پیدا نشود)
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

            // تراکنش کیف‌پول ایجادشده
            $table->foreignId('wallet_txn_id')->nullable()->constrained('wallet_transactions')->nullOnDelete();

            // تاریخ و زمان واریز در بانک (Jalali string — «1403/12/20»)
            $table->string('deposited_at_jalali', 30)->nullable()->comment('تاریخ واریز به فرمت شمسی');

            // زمان پردازش توسط الیت‌زون (null = هنوز پردازش نشده / خطا داشته)
            $table->timestamp('processed_at')->nullable();

            // یادداشت خطا یا توضیح
            $table->string('note', 255)->nullable()->comment('یادداشت خطا اگر کاربر پیدا نشد یا شناسه نامعتبر بود');

            $table->timestamps();

            // ایندکس‌های جستجو
            $table->index('national_code');
            $table->index('shenaseh_variz');
            $table->index(['user_id', 'processed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('podium_deposit_records');
    }
};
