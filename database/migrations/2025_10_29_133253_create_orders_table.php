<?php

use App\Models\Order;
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
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            // Use string literals for column names
            $table->foreignId('placer_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('beneficiary_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('address_id')->nullable()->constrained('addresses')->nullOnDelete();
            $table->string('order_number')->unique();
            $table->string('status')->default(Order::STATUS_PENDING);
            $table->decimal('subtotal', 15, 2)->default(0);
            $table->decimal('discount_amount', 15, 2)->default(0);
            $table->decimal('shipping_cost', 15, 2)->default(0);
            $table->decimal('vat_amount', 15, 2)->default(0);
            $table->decimal('final_amount', 15, 2)->default(0);
            $table->string('payment_method')->nullable();
            $table->string('payment_status')->default(Order::PAYMENT_PENDING);
            $table->text('notes')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('weighbridge_registered_at')->nullable();
            $table->string('initial_invoice_ref')->nullable();
            $table->string('final_invoice_ref')->nullable();
            $table->string('tax_invoice_ref')->nullable();
            $table->decimal('expert_fee_amount', 15, 2)->nullable();
            $table->string('expert_fee_payment_status')->nullable()->default(Order::FEE_PAYMENT_PENDING);
            $table->decimal('engineer_fee_amount', 15, 2)->nullable();
            $table->string('engineer_fee_payment_status')->nullable()->default(Order::FEE_PAYMENT_PENDING);
            $table->decimal('contractor_fee_amount', 15, 2)->nullable();
            $table->string('contractor_fee_payment_status')->nullable()->default(Order::FEE_PAYMENT_PENDING);
            $table->string('expert_fee_invoice_ref')->nullable(); // فاکتور مربوط به حق مشاور فنی
            $table->text('cancel_reason')->nullable(); // دلیل لغو سفارش (برای تب لغو شده)
            $table->string('survey_status')->nullable()->default(Order::SURVEY_PENDING);
            $table->timestamp('prepared_at')->nullable();
            $table->timestamp('shipped_at')->nullable();
            $table->string('payment_receipt_ref')->nullable();
            $table->string('espadana_order_ref')->nullable();
            $table->string('waybill_ref')->nullable();
            $table->timestamp(Order::RESERVATION_EXPIRES_AT)->nullable()
                ->comment('تاریخ انقضای رزرو (2 ساعته یا تمدید شده)');
            $table->decimal(Order::PARTIAL_PAYMENT_AMOUNT, 15, 2)->nullable()
                ->comment('مبلغ پرداخت شده برای تمدید رزرو');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
