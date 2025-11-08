<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('wallet_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('wallet_id')->constrained('wallets')->cascadeOnDelete();
            $table->foreignId('order_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->decimal('amount', 20, 0);

            // نوع تراکنش: واریز، برداشت، هولد سهم، آزادسازی سهم
            $table->string('type')->index();

            // وضعیت تراکنش: در انتظار، تایید شده، ناموفق
            $table->string('status')->index();

            $table->text('description')->nullable(); // توضیحات (مثل: "پورسانت پروژه ...")
            $table->json('meta')->nullable(); // برای ذخیره اطلاعات اضافه (مثلا رفرنس پرداخت)
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('wallet_transactions');
    }
};
