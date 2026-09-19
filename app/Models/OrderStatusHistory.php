<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $order_id
 * @property string|null $from_status
 * @property string $to_status
 * @property int|null $user_id
 * @property int|null $duration_minutes
 * @property Carbon $created_at
 * @property-read Order $order
 * @property-read User|null $user
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OrderStatusHistory newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OrderStatusHistory newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OrderStatusHistory query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OrderStatusHistory whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OrderStatusHistory whereDurationMinutes($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OrderStatusHistory whereFromStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OrderStatusHistory whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OrderStatusHistory whereOrderId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OrderStatusHistory whereToStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OrderStatusHistory whereUserId($value)
 *
 * @mixin \Eloquent
 */
class OrderStatusHistory extends Model
{
    use HasFactory;

    public $timestamps = false;

    /* -------------------------------------------------------------------------- */
    /* Constants (Column Names) */
    /* -------------------------------------------------------------------------- */
    const ID = 'id';

    const ORDER_ID = 'order_id';

    const FROM_STATUS = 'from_status';

    const TO_STATUS = 'to_status';

    const USER_ID = 'user_id';

    const DURATION_MINUTES = 'duration_minutes';

    const CREATED_AT = 'created_at';

    /* -------------------------------------------------------------------------- */
    /* Configuration */
    /* -------------------------------------------------------------------------- */
    protected $fillable = [
        self::ORDER_ID,
        self::FROM_STATUS,
        self::TO_STATUS,
        self::USER_ID,
        self::DURATION_MINUTES,
        // ⚠️ CREATED_AT باید fillable باشد. `$timestamps = false` است، پس Eloquent
        // خودش این ستون را پر نمی‌کند و `OrderObserver` صریحاً `now()` می‌فرستد —
        // ولی تا وقتی اینجا نبود، mass-assignment آن را **بی‌صدا دور می‌ریخت** و
        // ستون از `DEFAULT CURRENT_TIMESTAMP` مایگریشن (`useCurrent()`) پر می‌شد.
        //
        // یعنی این ستون با ساعتِ **MySQL** نوشته می‌شد در حالی که بقیه‌ی ستون‌های
        // زمانیِ سیستم (از جمله `orders.created_at`) با ساعتِ **PHP** و
        // `config('app.timezone') = UTC` نوشته می‌شوند. اگر tz سرورِ دیتابیس UTC
        // نباشد (روی این ماشین SYSTEM = UTC−۵، روی سرور ایران معمولاً +۳:۳۰)،
        // هر ردیفِ تاریخچه به‌اندازه‌ی همان اختلاف با بقیه‌ی رویدادها جابه‌جا می‌شد.
        // نتیجه‌اش در خطِ زمانیِ سفارش دیده شد: رویدادِ «تغییر وضعیت» پیش از
        // «ثبت سفارش» ظاهر می‌شد.
        self::CREATED_AT,
    ];

    protected $casts = [
        self::ORDER_ID => 'integer',
        self::USER_ID => 'integer',
        self::DURATION_MINUTES => 'integer',
        self::CREATED_AT => 'datetime',
    ];

    /* -------------------------------------------------------------------------- */
    /* Relationships */
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
