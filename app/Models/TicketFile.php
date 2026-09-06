<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * پیوستِ تیکت.
 *
 * فایل‌ها روی دیسکِ خصوصیِ `local` ذخیره می‌شوند، نه `public`. تیکت پشتیبانی
 * جایی است که کاربر فاکتور، تصویر کارت ملی و مدارک بانکی آپلود می‌کند؛ تا پیش
 * از این روی دیسکِ public می‌نشستند و Resource یک URLِ مستقیم و بدونِ احراز
 * هویت برمی‌گرداند. تنها راهِ خواندنشان حالا روتِ دانلودِ محافظت‌شده است
 * (`TicketFileController::download`).
 *
 * @property int $id
 * @property int|null $ticket_id
 * @property int|null $ticket_reply_id
 * @property string $path
 * @property string $original_name
 * @property string $mime_type
 * @property int $size
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Ticket|null $ticket
 * @property-read TicketReply|null $ticketReply
 *
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
 *
 * @mixin \Eloquent
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
    /** @return BelongsTo<Ticket, $this> */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class, self::TICKET_ID);
    }

    /** Set when the file is attached to a reply in the conversation thread. */
    /** @return BelongsTo<TicketReply, $this> */
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
