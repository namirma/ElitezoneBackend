<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Snapshot the supplier's formula-variant flags onto each order at payment time.
 *
 * These two columns mirror the flags on supplier_profiles but are copied onto
 * the order so that:
 *  - Historical recalculation (e.g. admin audit) always uses the rate that was
 *    active at the time of purchase, even if the supplier contract later changes.
 *  - ProcessWeighbridgeResultAction can read the flags directly from the locked
 *    order row without a supplier-profile join inside the transaction.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->boolean('trans_commission')
                ->default(true)
                ->after('consultant_share_rate')
                ->comment('+%TC snapshot: کمیسیون روی حمل؟ (از پروفایل سوپلایر در زمان پرداخت کپی می‌شود)');

            $table->boolean('trans_vat')
                ->default(true)
                ->after('trans_commission')
                ->comment('+VAT snapshot: مالیات روی حمل؟ (از پروفایل سوپلایر در زمان پرداخت کپی می‌شود)');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['trans_commission', 'trans_vat']);
        });
    }
};
