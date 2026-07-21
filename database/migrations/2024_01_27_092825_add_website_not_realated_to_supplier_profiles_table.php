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
        Schema::table('supplier_profiles', function (Blueprint $table) {
            $table->boolean('website_not_related')->after('website')->default(1);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('supplier_profiles', function (Blueprint $table) {
            if (Schema::hasColumn('supplier_profiles', 'website_not_related')) {
                $table->dropColumn('website_not_related');
            }
        });
    }
};
