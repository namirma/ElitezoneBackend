<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Ticket extends Model
{
    use SoftDeletes;

    // ── Column constants ──────────────────────────────────────────────────
    public const ID                    = 'id';
    public const CODE                  = 'code';
    public const USER_ID               = 'user_id';
    public const DEPARTMENT_ID         = 'department_id';
    public const PREVIOUS_DEPARTMENT_ID = 'previous_department_id';
    public const ADMIN_ID              = 'admin_id';
    public const SUBJECT               = 'subject';
    public const MESSAGE               = 'message';
    public const STATUS                = 'status';

    // ── Status values ─────────────────────────────────────────────────────
    public const STATUS_OPEN     = 'open';
    public const STATUS_ANSWERED = 'answered';
    public const STATUS_PENDING  = 'pending';
    public const STATUS_CLOSED   = 'closed';

    // ── TCK code prefix ───────────────────────────────────────────────────
    public const CODE_PREFIX = 'TCK-';

    protected $with = ['department'];

    protected $fillable = [
        self::CODE,
        self::USER_ID,
        self::DEPARTMENT_ID,
        self::PREVIOUS_DEPARTMENT_ID,
        self::ADMIN_ID,
        self::SUBJECT,
        self::MESSAGE,
        self::STATUS,
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function replies(): HasMany
    {
        return $this->hasMany(TicketReply::class);
    }
}
