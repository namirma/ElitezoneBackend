<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();

            // شناسه‌ها
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            // اطلاعات سند
            $table->string('document_code')->comment('کد سند طبق FinancialCodes مثل LO, G, A');
            $table->string('invoice_number')->unique()->comment('شماره سریال تولید شده سیستم');
            $table->decimal('amount', 20, 2)->default(0)->comment('مبلغ فاکتور/رسید');
            $table->string('file_path')->nullable()->comment('مسیر فایل PDF ذخیره شده');
            $table->string('status')->default('issued');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
