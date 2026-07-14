<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $advisor_id
 * @property string $mobile
 * @property \Illuminate\Support\Carbon|null $sent_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\User $advisor
 * @method static \Illuminate\Database\Eloquent\Builder|EmployerSmsInvite newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|EmployerSmsInvite newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|EmployerSmsInvite query()
 * @method static \Illuminate\Database\Eloquent\Builder|EmployerSmsInvite whereAdvisorId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|EmployerSmsInvite whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|EmployerSmsInvite whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|EmployerSmsInvite whereMobile($value)
 * @method static \Illuminate\Database\Eloquent\Builder|EmployerSmsInvite whereSentAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|EmployerSmsInvite whereUpdatedAt($value)
 * @mixin \Eloquent
 */
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
