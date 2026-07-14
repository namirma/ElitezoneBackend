<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $order_id
 * @property string|null $from_status
 * @property string $to_status
 * @property int|null $user_id
 * @property int|null $duration_minutes
 * @property \Illuminate\Support\Carbon $created_at
 * @property-read \App\Models\Order $order
 * @property-read \App\Models\User|null $user
 * @method static \Illuminate\Database\Eloquent\Builder|OrderStatusHistory newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|OrderStatusHistory newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|OrderStatusHistory query()
 * @method static \Illuminate\Database\Eloquent\Builder|OrderStatusHistory whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|OrderStatusHistory whereDurationMinutes($value)
 * @method static \Illuminate\Database\Eloquent\Builder|OrderStatusHistory whereFromStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder|OrderStatusHistory whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|OrderStatusHistory whereOrderId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|OrderStatusHistory whereToStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder|OrderStatusHistory whereUserId($value)
 * @mixin \Eloquent
 */
class OrderStatusHistory extends Model
{
    use HasFactory;

    public $timestamps = false;

    /* -------------------------------------------------------------------------- */
    /* Constants (Column Names)                                                   */
    /* -------------------------------------------------------------------------- */
    const ID = 'id';

    const ORDER_ID = 'order_id';

    const FROM_STATUS = 'from_status';

    const TO_STATUS = 'to_status';

    const USER_ID = 'user_id';

    const DURATION_MINUTES = 'duration_minutes';

    const CREATED_AT = 'created_at';

    /* -------------------------------------------------------------------------- */
    /* Configuration                                                              */
    /* -------------------------------------------------------------------------- */
    protected $fillable = [
        self::ORDER_ID,
        self::FROM_STATUS,
        self::TO_STATUS,
        self::USER_ID,
        self::DURATION_MINUTES,
    ];

    protected $casts = [
        self::ORDER_ID => 'integer',
        self::USER_ID => 'integer',
        self::DURATION_MINUTES => 'integer',
        self::CREATED_AT => 'datetime',
    ];

    /* -------------------------------------------------------------------------- */
    /* Relationships                                                              */
    /* -------------------------------------------------------------------------- */

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, self::ORDER_ID);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, self::USER_ID);
    }
}
