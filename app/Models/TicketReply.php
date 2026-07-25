<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TicketReply extends Model
{
    use HasFactory;

    protected $fillable = [
        'ticket_id',
        'user_id',
        'admin_id',
        'message',
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
