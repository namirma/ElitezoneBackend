<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TicketReply extends Model
{
    use HasFactory;

    // ثابت‌های نام ستون — قرارداد پروژه (`Order::STATUS` و…). این مدل تنها مدلی
    // در مسیر تیکت بود که نداشت، و منطقِ «خوانده‌نشده» روی `admin_id` تصمیم
    // می‌گیرد؛ رشته‌ی خام در آن مسیر یعنی یک تایپو = شمارشِ بی‌صدا غلط.
    public const ID = 'id';

    public const TICKET_ID = 'ticket_id';

    public const USER_ID = 'user_id';

    /** NULL یعنی پاسخِ کاربر؛ مقدارِ غیر NULL یعنی پاسخِ پشتیبانی. */
    public const ADMIN_ID = 'admin_id';

    public const MESSAGE = 'message';

    public const CREATED_AT = 'created_at';

    public const UPDATED_AT = 'updated_at';

    protected $fillable = [
        self::TICKET_ID,
        self::USER_ID,
        self::ADMIN_ID,
        self::MESSAGE,
    ];

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * ادمینی که این پاسخ را نوشته (null یعنی پاسخِ خودِ کاربر).
     *
     * ⚠️ این relation قبلاً وجود نداشت در حالی که TicketReplyResource آن را
     * سریالایز می‌کرد؛ نتیجه: `admin` همیشه null بود و هر پاسخِ پشتیبانی در
     * پنل و در چتِ کاربر به‌اسم خودِ کاربر نمایش داده می‌شد.
     */
    public function admin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'admin_id');
    }

    public function files(): HasMany
    {
        return $this->hasMany(TicketFile::class, TicketFile::TICKET_REPLY_ID);
    }
}
