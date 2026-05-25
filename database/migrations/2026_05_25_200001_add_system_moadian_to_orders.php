<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add Doc-P tracking columns to the orders table.
 *
 * system_moadian_ref        — Doc P: reference number of the Moadian invoice
 *                             issued by Saze (the platform) to the buyer.
 *                             Null until an admin submits it via
 *                             POST /admin/orders/{order}/system-moadian-invoice.
 *
 * system_hold_released_at   — Timestamp when the platform's sazeVat was
 *                             released from WVAT balance_hold → WVAT balance
 *                             (i.e. when Doc P was accepted).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('system_moadian_ref', 100)->nullable()
                ->after('supplier_debt_amount')
                ->comment('Doc P: شماره فاکتور مودیان صادر شده توسط سازه (پلتفرم) برای خریدار');

            $table->timestamp('system_hold_released_at')->nullable()
                ->after('system_moadian_ref')
                ->comment('زمان آزادسازی هولد مالیات پلتفرم (sazeVat) از WVAT');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn([
                'system_moadian_ref',
                'system_hold_released_at',
            ]);
        });
    }
};
