<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * درخواستِ لغوِ دوطرفه‌ی سفارش — پرسش ۴ مدیریت.
 *
 * چرخه:
 *
 *   pending   ← ثبت (خریدار یا تأمین‌کننده)؛ طرفِ مقابل تا `respond_until` پاسخ می‌دهد
 *   approved  ← طرفِ مقابل پذیرفت → سفارش همان لحظه از `CancelOrderAction` لغو می‌شود
 *   escalated ← طرفِ مقابل رد کرد (با دلیل) **یا** مهلت بی‌پاسخ گذشت → صفِ بک‌آفیس
 *   approved  ← بک‌آفیس پذیرفت → `CancelOrderAction` (پول از تأمین‌کننده به خریدار)
 *   rejected  ← بک‌آفیس رد کرد → سفارش ادامه می‌یابد؛ دلیل به هر دو طرف می‌رسد
 *
 * همه‌ی ستون‌های تصمیم عمداً خارج از `$fillable`اند — همان قاعده‌ی
 * `SupplierRequest`: تصمیم فقط از اکشن نوشته می‌شود، نه با بدنه‌ی درخواست.
 */
class OrderCancelRequest extends Model
{
    public const ID = 'id';

    public const ORDER_ID = 'order_id';

    public const REQUESTED_BY = 'requested_by';

    public const REQUESTER_USER_ID = 'requester_user_id';

    public const REASON = 'reason';

    public const RESPOND_UNTIL = 'respond_until';

    public const COUNTERPARTY_DECISION = 'counterparty_decision';

    public const COUNTERPARTY_REASON = 'counterparty_reason';

    public const RESPONDED_AT = 'responded_at';

    public const ESCALATED_AT = 'escalated_at';

    public const ADMIN_DECISION = 'admin_decision';

    public const ADMIN_ID = 'admin_id';

    public const ADMIN_REASON = 'admin_reason';

    public const DECIDED_AT = 'decided_at';

    public const STATUS = 'status';

    public const SIDE_BUYER = 'buyer';

    public const SIDE_SUPPLIER = 'supplier';

    public const STATUS_PENDING = 'pending';

    public const STATUS_ESCALATED = 'escalated';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_ESCALATED,
        self::STATUS_APPROVED,
        self::STATUS_REJECTED,
    ];

    public const DECISION_ACCEPTED = 'accepted';

    public const DECISION_REJECTED = 'rejected';

    public const DECISION_APPROVED = 'approved';

    /** «باز» یعنی هنوز روی سفارش اثر می‌گذارد: هیچ درخواستِ دومی تا بسته‌شدنش ثبت نمی‌شود. */
    public const OPEN_STATUSES = [self::STATUS_PENDING, self::STATUS_ESCALATED];

    protected $fillable = [
        self::ORDER_ID,
        self::REQUESTED_BY,
        self::REQUESTER_USER_ID,
        self::REASON,
    ];

    protected $casts = [
        self::RESPOND_UNTIL => 'datetime',
        self::RESPONDED_AT => 'datetime',
        self::ESCALATED_AT => 'datetime',
        self::DECIDED_AT => 'datetime',
    ];

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, self::ORDER_ID);
    }

    /** @return BelongsTo<User, $this> */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, self::REQUESTER_USER_ID);
    }

    /** @return BelongsTo<User, $this> */
    public function admin(): BelongsTo
    {
        return $this->belongsTo(User::class, self::ADMIN_ID);
    }

    public function isPending(): bool
    {
        return $this->{self::STATUS} === self::STATUS_PENDING;
    }

    public function isEscalated(): bool
    {
        return $this->{self::STATUS} === self::STATUS_ESCALATED;
    }

    public function isOpen(): bool
    {
        return in_array($this->{self::STATUS}, self::OPEN_STATUSES, true);
    }

    /** سمتِ مقابلِ درخواست‌کننده. */
    public function counterpartySide(): string
    {
        return $this->{self::REQUESTED_BY} === self::SIDE_BUYER ? self::SIDE_SUPPLIER : self::SIDE_BUYER;
    }
}
