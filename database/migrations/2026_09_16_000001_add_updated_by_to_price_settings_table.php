<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('price_settings', function (Blueprint $table) {
            // کدام ادمین آخرین‌بار این پارامتر را عوض کرد — پنلِ «پارامترهای مالی».
            $table->foreignId('updated_by')->nullable()->after('description')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('price_settings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('updated_by');
        });
    }
};
