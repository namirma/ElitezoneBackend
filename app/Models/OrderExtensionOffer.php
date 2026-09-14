<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * پیشنهادِ تمدیدِ رزرو از سوی تأمین‌کننده — پرسش ۳ مدیریت.
 *
 * تا خریدار نپذیرد هیچ اثری روی مهلتِ سفارش ندارد. پذیرش از
 * `ExtendReserveDeadlineAction` می‌گذرد (همان مسیرِ قدیمیِ تمدید)، رد از
 * `CancelOrderAction` — هیچ‌کدام این‌جا دوباره نوشته نشده‌اند.
 *
 * `status`/`responded_at` عمداً fillable نیستند: پاسخ فقط از اکشن نوشته می‌شود.
 */
class OrderExtensionOffer extends Model
{
    public const ID = 'id';

    public const ORDER_ID = 'order_id';

    public const SUPPLIER_USER_ID = 'supplier_user_id';

    public const HOURS = 'hours';

    public const PROPOSED_UNTIL = 'proposed_until';

    public const STATUS = 'status';

    public const RESPONDED_AT = 'responded_at';

    public const STATUS_PENDING = 'pending';

    public const STATUS_ACCEPTED = 'accepted';

    public const STATUS_REJECTED = 'rejected';

    /** رزرو پیش از پاسخِ خریدار لغو/تکمیل شد — پیشنهاد دیگر موضوعیت ندارد. */
    public const STATUS_EXPIRED = 'expired';

    protected $fillable = [
        self::ORDER_ID,
        self::SUPPLIER_USER_ID,
        self::HOURS,
        self::PROPOSED_UNTIL,
    ];

    protected $casts = [
        self::HOURS => 'integer',
        self::PROPOSED_UNTIL => 'datetime',
        self::RESPONDED_AT => 'datetime',
    ];

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, self::ORDER_ID);
    }

    /** @return BelongsTo<User, $this> */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(User::class, self::SUPPLIER_USER_ID);
    }

    public function isPending(): bool
    {
        return $this->{self::STATUS} === self::STATUS_PENDING;
    }

    /**
     * `proposed_until` گذشته: پذیرفتنش مهلتی در گذشته می‌ساخت (accept ۴۲۲ می‌دهد)،
     * پس نه به خریدار نشان داده می‌شود و نه جلوی پیشنهادِ تازه را می‌گیرد.
     */
    public function isLapsed(): bool
    {
        return $this->{self::PROPOSED_UNTIL}->isPast();
    }

    /**
     * تا کِی خریدار واقعاً می‌تواند بپذیرد: هرکدام زودتر — `proposed_until`، یا پایانِ
     * ارفاقِ رزرو که کرون در آن لغو می‌کند.
     */
    public function respondUntil(Order $order): CarbonInterface
    {
        $until = $this->{self::PROPOSED_UNTIL};
        $endsAt = $order->reserveEndsAt();

        return $endsAt !== null && $endsAt->lessThan($until) ? $endsAt : $until;
    }
}
