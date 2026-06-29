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
        Schema::table('addresses', function (Blueprint $table) {
            $table->string('receiver_name')->after('postal_code');
            $table->string('receiver_mobile')->after('receiver_name');
            $table->string('title')->nullable()->after('user_id');
        });
    }

    public function down(): void
    {
        Schema::table('addresses', function (Blueprint $table) {
            $table->dropColumn(['receiver_name', 'receiver_mobile', 'title']);
        });
    }
};
