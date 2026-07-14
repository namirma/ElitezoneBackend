<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int|null $ticket_id
 * @property int|null $ticket_reply_id
 * @property string $path
 * @property string $original_name
 * @property string $mime_type
 * @property int $size
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\Ticket|null $ticket
 * @property-read \App\Models\TicketReply|null $ticketReply
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TicketFile newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TicketFile newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TicketFile query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TicketFile whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TicketFile whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TicketFile whereMimeType($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TicketFile whereOriginalName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TicketFile wherePath($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TicketFile whereSize($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TicketFile whereTicketId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TicketFile whereTicketReplyId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TicketFile whereUpdatedAt($value)
 * @mixin \Eloquent
 */
class TicketFile extends Model
{
    public const TICKET_ID = 'ticket_id';

    public const TICKET_REPLY_ID = 'ticket_reply_id';

    public const PATH = 'path';

    public const ORIGINAL_NAME = 'original_name';

    public const MIME_TYPE = 'mime_type';

    public const SIZE = 'size';

    protected $fillable = [
        self::TICKET_ID,
        self::TICKET_REPLY_ID,
        self::PATH,
        self::ORIGINAL_NAME,
        self::MIME_TYPE,
        self::SIZE,
    ];

    /** Set when the file is attached to a ticket's opening message. */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class, self::TICKET_ID);
    }

    /** Set when the file is attached to a reply in the conversation thread. */
    public function ticketReply(): BelongsTo
    {
        return $this->belongsTo(TicketReply::class);
    }
}
