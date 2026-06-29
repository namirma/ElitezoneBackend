<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add formula-variant flags to supplier_profiles.
 *
 * trans_commission — whether Saze charges commission on the transportation cost (Trans).
 *   true  (+%TC): commission is applied to Trans   → default
 *   false (-%TC): Trans is excluded from commission deduction
 *
 * trans_vat — whether VAT is charged on the transportation cost.
 *   true  (+VAT): VAT applies to Trans             → default
 *   false (-VAT): Trans is excluded from VAT
 *
 * Together these four combinations select which of the four POO / PTS formula
 * variants the SettlementCalculatorService should use for each supplier.
 * Values are set per supplier contract and are snapshotted onto the orders table
 * at payment time so historical calculation always uses the rate in effect at
 * the moment of purchase.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supplier_profiles', function (Blueprint $table) {
            $table->boolean('trans_commission')
                ->default(true)
                ->after('company')
                ->comment('+%TC: آیا کمیسیون روی هزینه حمل اعمال می‌شود؟');

            $table->boolean('trans_vat')
                ->default(true)
                ->after('trans_commission')
                ->comment('+VAT: آیا مالیات بر ارزش افزوده روی هزینه حمل اعمال می‌شود؟');
        });
    }

    public function down(): void
    {
        Schema::table('supplier_profiles', function (Blueprint $table) {
            $table->dropColumn(['trans_commission', 'trans_vat']);
        });
    }
};
