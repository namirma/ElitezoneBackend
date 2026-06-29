<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerStat extends Model
{
    use HasFactory;

    /* -------------------------------------------------------------------------- */
    /* Constants (Column Names)                                                   */
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
    /* Constants (Segments - برچسب‌های مشتریان)                                  */
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
