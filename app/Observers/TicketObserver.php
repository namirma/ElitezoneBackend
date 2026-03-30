<?php

namespace App\Observers;

use App\Models\Ticket;

class TicketObserver
{
    public function created(Ticket $ticket): void
    {
        //TODO change pattern later
        $ticket->code = $ticket->id + 100000;
        $ticket->saveQuietly();
    }
}
