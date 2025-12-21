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
    const
        ID = 'id',
        USER_ID = 'user_id',
        RECENCY_DAYS = 'recency_days',
        FREQUENCY_COUNT = 'frequency_count',
        MONETARY_TOTAL = 'monetary_total',
        R_SCORE = 'r_score',
        F_SCORE = 'f_score',
        M_SCORE = 'm_score',
        SEGMENT = 'segment',
        LAST_CALCULATED_AT = 'last_calculated_at';

    /* -------------------------------------------------------------------------- */
    /* Constants (Segments - برچسب‌های مشتریان)                                  */
    /* -------------------------------------------------------------------------- */
    const
        SEGMENT_CHAMPION = 'champion',         
        SEGMENT_LOYAL = 'loyal',               
        SEGMENT_POTENTIAL_LOYAL = 'potential', 
        SEGMENT_AT_RISK = 'at_risk',           
        SEGMENT_HIBERNATING = 'hibernating',   
        SEGMENT_LOST = 'lost',                 
        SEGMENT_NEW = 'new';                   

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
