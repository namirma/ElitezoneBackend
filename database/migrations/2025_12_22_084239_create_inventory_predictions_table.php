<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_predictions', function (Blueprint $table) {
            $table->id();

            // ارتباط با محصول (Variant)
            $table->foreignId('product_variant_id')->constrained()->cascadeOnDelete();

            // 1. داده‌های ورودی (Facts)
            $table->integer('current_stock');
            $table->decimal('sales_velocity_30d', 10, 2);
            $table->integer('search_volume_7d');

            // 2. خروجی هوش مصنوعی (Predictions)
            $table->decimal('predicted_demand_next_7d', 10, 2);
            $table->decimal('recommended_restock_amount', 10, 2);

            // 3. اولویت و دلیل
            $table->string('priority', 20);
            $table->string('reason')->nullable();

            $table->timestamp('calculated_at')->useCurrent();

            // ایندکس برای سرعت گزارش‌گیری
            $table->index(['priority', 'predicted_demand_next_7d']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_predictions');
    }
};
