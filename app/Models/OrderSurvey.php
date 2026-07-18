<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderSurvey extends Model
{
    use HasFactory;

    public const ID = 'id';

    public const ORDER_ID = 'order_id';

    public const USER_ID = 'user_id';

    /** نوع نظرسنجی — سند S (خریدار) یا سند T (الیت یار) طبق سند طراحی. */
    public const TYPE = 'type';

    public const TYPE_BUYER = 'buyer';

    public const TYPE_ELITE_YAR = 'elite_yar';

    public const RATING = 'rating';

    public const COMMENT = 'comment';

    public const CREATED_AT = 'created_at';

    public const UPDATED_AT = 'updated_at';

    protected $fillable = [
        self::ORDER_ID,
        self::USER_ID,
        self::TYPE,
        self::RATING,
        self::COMMENT,
    ];

    protected $casts = [
        self::RATING => 'integer',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, self::ORDER_ID);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, self::USER_ID);
    }
}
