<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TicketFile extends Model
{
    public const TICKET_REPLY_ID = 'ticket_reply_id';
    public const PATH = 'path';
    public const ORIGINAL_NAME = 'original_name';
    public const MIME_TYPE = 'mime_type';
    public const SIZE = 'size';

    protected $fillable = [
        self::TICKET_REPLY_ID,
        self::PATH,
        self::ORIGINAL_NAME,
        self::MIME_TYPE,
        self::SIZE,
    ];

    public function ticketReply(): BelongsTo
    {
        return $this->belongsTo(TicketReply::class);
    }
}
