<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Internal staff note attached to a single ticket ("یادداشت های تیکت").
 * Never exposed to the customer-facing ticket resource.
 */
class TicketNote extends Model
{
    public const ID = 'id';

    public const TICKET_ID = 'ticket_id';

    public const ADMIN_ID = 'admin_id';

    public const NOTE = 'note';

    protected $fillable = [
        self::TICKET_ID,
        self::ADMIN_ID,
        self::NOTE,
    ];

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(User::class, self::ADMIN_ID);
    }
}
