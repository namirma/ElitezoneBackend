<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $order_id
 * @property int $user_id
 * @property int $rating
 * @property string|null $comment
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\Order $order
 * @property-read \App\Models\User $user
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OrderSurvey newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OrderSurvey newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OrderSurvey query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OrderSurvey whereComment($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OrderSurvey whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OrderSurvey whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OrderSurvey whereOrderId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OrderSurvey whereRating($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OrderSurvey whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OrderSurvey whereUserId($value)
 * @mixin \Eloquent
 */
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
