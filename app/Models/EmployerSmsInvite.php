<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployerSmsInvite extends Model
{
    use HasFactory;

    public const ID = 'id';
    public const ADVISOR_ID = 'advisor_id';
    public const MOBILE = 'mobile';
    public const SENT_AT = 'sent_at';

    /** حداکثر تعداد کارفرمایی که هر مشاور می‌تواند دعوت کند */
    public const MAX_INVITES = 10;

    protected $fillable = [
        self::ADVISOR_ID,
        self::MOBILE,
        self::SENT_AT,
    ];

    protected $casts = [
        self::SENT_AT => 'datetime',
    ];

    public function advisor(): BelongsTo
    {
        return $this->belongsTo(User::class, self::ADVISOR_ID);
    }
}
