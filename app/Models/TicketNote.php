<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Internal staff note attached to a single ticket ("یادداشت های تیکت").
 *
 * Never exposed to the customer-facing ticket resource.
 *
 * @property int $id
 * @property int $ticket_id
 * @property int|null $admin_id
 * @property string $note
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User|null $admin
 * @property-read Ticket|null $ticket
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TicketNote newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TicketNote newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TicketNote query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TicketNote whereAdminId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TicketNote whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TicketNote whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TicketNote whereNote($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TicketNote whereTicketId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TicketNote whereUpdatedAt($value)
 *
 * @mixin \Eloquent
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
