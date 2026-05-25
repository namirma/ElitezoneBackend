<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Track Moadian (taxpayer system) invoice references on each order.
 *
 * consultant_moadian_ref        — Doc Q: reference number of the Moadian invoice
 *                                 issued by the technical consultant to Saze.
 *                                 Null until the consultant submits it.
 * consultant_hold_released_at   — Timestamp when the consultant's commission
 *                                 was moved from balance_hold → balance (i.e.
 *                                 when Doc Q was accepted and hold was released).
 *
 * supplier_moadian_ref          — Doc O: reference number of the Moadian invoice
 *                                 issued by the supplier to Saze.
 *                                 Null until the supplier submits it.
 * supplier_hold_released_at     — Timestamp when the supplier's VAT hold was
 *                                 moved from WVAT hold → WVAT available.
 *
 * supplier_debt_pending         — true when the deficit-weighbridge flow found
 *                                 that the supplier wallet had insufficient balance
 *                                 to cover the reclaim amount (PTS − PTSM).
 *                                 In this state the buyer's refund sits in
 *                                 balance_hold pending the supplier topping up.
 * supplier_debt_amount          — The Rial amount the supplier still owes
 *                                 (PTS − PTSM) when supplier_debt_pending = true.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // Doc Q — consultant Moadian invoice
            $table->string('consultant_moadian_ref', 100)->nullable()
                ->after('weighing_completed_at')
                ->comment('Doc Q: شماره فاکتور مودیان صادر شده توسط مشاور فنی');
            $table->timestamp('consultant_hold_released_at')->nullable()
                ->after('consultant_moadian_ref')
                ->comment('زمان آزادسازی هولد کمیسیون مشاور');

            // Doc O — supplier Moadian invoice
            $table->string('supplier_moadian_ref', 100)->nullable()
                ->after('consultant_hold_released_at')
                ->comment('Doc O: شماره فاکتور مودیان صادر شده توسط سوپلایر');
            $table->timestamp('supplier_hold_released_at')->nullable()
                ->after('supplier_moadian_ref')
                ->comment('زمان آزادسازی هولد مالیات سوپلایر در WVAT');

            // Supplier deficit tracking
            $table->boolean('supplier_debt_pending')->default(false)
                ->after('supplier_hold_released_at')
                ->comment('آیا سوپلایر بدهی باسکول دارد که هنوز تسویه نشده؟');
            $table->decimal('supplier_debt_amount', 20, 2)->default(0)
                ->after('supplier_debt_pending')
                ->comment('مبلغ بدهی سوپلایر بابت کسری باسکول (ریال)');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn([
                'consultant_moadian_ref',
                'consultant_hold_released_at',
                'supplier_moadian_ref',
                'supplier_hold_released_at',
                'supplier_debt_pending',
                'supplier_debt_amount',
            ]);
        });
    }
};
