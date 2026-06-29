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
        Schema::table('user_bank_accounts', function (Blueprint $table) {
            $table->string('card_number', 16)->nullable()->unique()->after('shaba');
            $table->string('shaba', 26)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('user_bank_accounts', function (Blueprint $table) {
            $table->dropColumn('card_number');
        });
    }
};
