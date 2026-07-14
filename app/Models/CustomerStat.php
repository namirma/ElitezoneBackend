<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $user_id
 * @property int $recency_days
 * @property int $frequency_count
 * @property numeric $monetary_total
 * @property int $r_score
 * @property int $f_score
 * @property int $m_score
 * @property string|null $segment
 * @property \Illuminate\Support\Carbon $last_calculated_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\User $user
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CustomerStat newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CustomerStat newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CustomerStat query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CustomerStat whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CustomerStat whereFScore($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CustomerStat whereFrequencyCount($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CustomerStat whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CustomerStat whereLastCalculatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CustomerStat whereMScore($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CustomerStat whereMonetaryTotal($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CustomerStat whereRScore($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CustomerStat whereRecencyDays($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CustomerStat whereSegment($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CustomerStat whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CustomerStat whereUserId($value)
 * @mixin \Eloquent
 */
class CustomerStat extends Model
{
    use HasFactory;

    /* -------------------------------------------------------------------------- */
    /* Constants (Column Names) */
    /* -------------------------------------------------------------------------- */
    const ID = 'id';

    const USER_ID = 'user_id';

    const RECENCY_DAYS = 'recency_days';

    const FREQUENCY_COUNT = 'frequency_count';

    const MONETARY_TOTAL = 'monetary_total';

    const R_SCORE = 'r_score';

    const F_SCORE = 'f_score';

    const M_SCORE = 'm_score';

    const SEGMENT = 'segment';

    const LAST_CALCULATED_AT = 'last_calculated_at';

    /* -------------------------------------------------------------------------- */
    /* Constants (Segments - برچسب‌های مشتریان) */
    /* -------------------------------------------------------------------------- */
    const SEGMENT_CHAMPION = 'champion';

    const SEGMENT_LOYAL = 'loyal';

    const SEGMENT_POTENTIAL_LOYAL = 'potential';

    const SEGMENT_AT_RISK = 'at_risk';

    const SEGMENT_HIBERNATING = 'hibernating';

    const SEGMENT_LOST = 'lost';

    const SEGMENT_NEW = 'new';

    protected $fillable = [
        self::USER_ID,
        self::RECENCY_DAYS,
        self::FREQUENCY_COUNT,
        self::MONETARY_TOTAL,
        self::R_SCORE,
        self::F_SCORE,
        self::M_SCORE,
        self::SEGMENT,
        self::LAST_CALCULATED_AT,
    ];

    protected $casts = [
        self::RECENCY_DAYS => 'integer',
        self::FREQUENCY_COUNT => 'integer',
        self::MONETARY_TOTAL => 'decimal:0',
        self::LAST_CALCULATED_AT => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, self::USER_ID);
    }
}
