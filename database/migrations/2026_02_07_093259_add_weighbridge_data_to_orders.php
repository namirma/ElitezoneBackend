<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->decimal('weight_after_scale', 15, 3)->nullable()->comment('WAS: وزن باسکول');
            $table->timestamp('weighing_completed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['weight_after_scale', 'weighing_completed_at']);
        });
    }
};
