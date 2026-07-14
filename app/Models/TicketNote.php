<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Internal staff note attached to a single ticket ("یادداشت های تیکت").
 *
 * Never exposed to the customer-facing ticket resource.
 *
 * @property int $id
 * @property int $ticket_id
 * @property int|null $admin_id
 * @property string $note
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\User|null $admin
 * @property-read \App\Models\Ticket $ticket
 * @method static \Illuminate\Database\Eloquent\Builder|TicketNote newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|TicketNote newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|TicketNote query()
 * @method static \Illuminate\Database\Eloquent\Builder|TicketNote whereAdminId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|TicketNote whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|TicketNote whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|TicketNote whereNote($value)
 * @method static \Illuminate\Database\Eloquent\Builder|TicketNote whereTicketId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|TicketNote whereUpdatedAt($value)
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
