<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ticket-specific internal notes ("یادداشت های تیکت" in the spec) — distinct from
 * the user-general notes already stored in `user_notes` ("یادداشت های عمومی").
 *
 * These are private staff notes attached to one ticket, never shown to the customer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ticket_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->constrained('tickets')->cascadeOnDelete();
            $table->foreignId('admin_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('note');
            $table->timestamps();

            $table->index('ticket_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_notes');
    }
};
