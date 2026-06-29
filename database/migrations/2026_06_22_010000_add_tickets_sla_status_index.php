<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            // CheckReservationExpiryJob و KPI queries فیلتر روی status+sla_due_at دارند
            $table->index(['status', 'sla_due_at'], 'tickets_status_sla_due_at_idx');
        });
    }

    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropIndex('tickets_status_sla_due_at_idx');
        });
    }
};
