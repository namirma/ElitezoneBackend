<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lets a ticket attachment belong directly to a ticket (its opening message),
 * not only to a reply.
 *
 * Fixes a real bug: TicketDTO accepts/validates `file.*` on ticket creation but
 * TicketService::create silently dropped them — TicketFile required a
 * ticket_reply_id, and creating a ticket creates no reply. TicketResource also
 * already referenced a Ticket `files` relation that did not exist. After this,
 * opening-message attachments are stored against the ticket and surfaced correctly.
 *
 * A file belongs to exactly one of (ticket_id, ticket_reply_id); both are now nullable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ticket_files', function (Blueprint $table) {
            $table->foreignId('ticket_id')->nullable()->after('id')->constrained('tickets')->cascadeOnDelete();
        });

        // Opening-message files have no reply → ticket_reply_id must be nullable.
        DB::statement('ALTER TABLE ticket_files MODIFY ticket_reply_id BIGINT UNSIGNED NULL');
    }

    public function down(): void
    {
        Schema::table('ticket_files', function (Blueprint $table) {
            $table->dropConstrainedForeignId('ticket_id');
        });

        DB::statement('ALTER TABLE ticket_files MODIFY ticket_reply_id BIGINT UNSIGNED NOT NULL');
    }
};
