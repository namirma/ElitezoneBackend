<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * پیوستِ تیکت.
 *
 * فایل‌ها روی دیسکِ خصوصیِ `local` ذخیره می‌شوند، نه `public`. تیکت پشتیبانی
 * جایی است که کاربر فاکتور، تصویر کارت ملی و مدارک بانکی آپلود می‌کند؛ تا پیش
 * از این روی دیسکِ public می‌نشستند و Resource یک URLِ مستقیم و بدونِ احراز
 * هویت برمی‌گرداند. تنها راهِ خواندنشان حالا روتِ دانلودِ محافظت‌شده است
 * (`TicketFileController::download`).
 */
class TicketFile extends Model
{
    /** دیسکِ خصوصیِ نگهداریِ پیوست‌ها — هرگز 'public' نشود. */
    public const DISK = 'local';

    /** پوشهٔ ذخیره روی دیسکِ خصوصی. */
    public const DIRECTORY = 'ticket-files';

    public const ID = 'id';

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

    /**
     * تیکتی که این پیوست به آن تعلق دارد — چه مستقیم (پیام اول) و چه از طریق
     * پاسخ. مبنای بررسیِ دسترسی در روتِ دانلود است.
     */
    public function resolveTicket(): ?Ticket
    {
        if ($this->{self::TICKET_ID}) {
            return $this->ticket;
        }

        return $this->ticketReply?->ticket;
    }
}
