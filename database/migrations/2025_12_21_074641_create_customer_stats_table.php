<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_stats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete()->unique();
            $table->integer('recency_days')->default(0);
            $table->integer('frequency_count')->default(0);
            $table->decimal('monetary_total', 20, 0)->default(0);
            $table->tinyInteger('r_score')->default(1);
            $table->tinyInteger('f_score')->default(1);
            $table->tinyInteger('m_score')->default(1);
            $table->string('segment')->nullable()->index();

            $table->timestamp('last_calculated_at')->useCurrent();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_stats');
    }
};
