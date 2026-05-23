<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;

/**
 * Admin ticket management — not yet implemented.
 *
 * When ready, implement the following methods and wire them to routes/api.php:
 *
 *   index(Request $request)   — paginated list with status/priority filters
 *   show(Ticket $ticket)      — detail view with messages and attachments
 *   reply(Request $request, Ticket $ticket) — admin reply, updates status → 'answered'
 *   close(Ticket $ticket)     — sets status → 'closed'
 *
 * Prerequisites before implementing:
 *   - Ticket::messages() HasMany relation must exist
 *   - Ticket::attachments() relation (Spatie Media Library or custom)
 *   - Routes added to routes/api.php under admin middleware group
 */
class AdminTicketController extends Controller
{
    //
}
