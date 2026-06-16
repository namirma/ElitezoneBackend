<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the consultant's badge-purchase invoice amount to the users table.
 *
 * Spec ("پروسه خرید", lines ~1654 / ~2228): the total lifetime commission paid to a
 * gold/silver/bronze technical consultant may not exceed `cap_multiplier × the invoice
 * the consultant paid when purchasing their badge`. This column stores that invoice
 * amount (in Rials) so WeighbridgeVatRouter can enforce the cap.
 *
 * NULL = no paid badge on record (e.g. pioneer/normal) → the consultant is uncapped,
 * preserving the previous behaviour.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->decimal('badge_purchase_amount', 20, 2)
                ->nullable()
                ->after('badge_id')
                ->comment('Invoice amount (Rials) the consultant paid for their badge; basis for the lifetime commission cap. NULL = uncapped.');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('badge_purchase_amount');
        });
    }
};
