<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * درخواستِ تأمین‌کننده به بک‌آفیس — لغوِ سفارش، یا افزودنِ کالا به کاتالوگ.
 *
 * هر دو عمداً **درخواست**اند نه اقدام: لغوِ اجباری طبق بند ۵.۳ تنها اختیارِ
 * وضعیتیِ ادمین است و کاتالوگ هم ادمین‌محور می‌ماند. این جدول فقط صف است؛
 * اقدامِ واقعی از همان مسیرهای موجود انجام می‌شود.
 */
class SupplierRequest extends Model
{
    use HasFactory;

    public const ID = 'id';

    public const SUPPLIER_USER_ID = 'supplier_user_id';

    public const TYPE = 'type';

    public const ORDER_ID = 'order_id';

    public const TICKET_ID = 'ticket_id';

    public const REASON = 'reason';

    public const PAYLOAD = 'payload';

    public const STATUS = 'status';

    public const REVIEW_NOTE = 'review_note';

    public const REVIEWED_BY = 'reviewed_by';

    public const REVIEWED_AT = 'reviewed_at';

    /** لغوِ سفارش — ادمین با `forceCancel` انجامش می‌دهد. */
    public const TYPE_ORDER_CANCELLATION = 'order_cancellation';

    /** افزودنِ محصول/واریانت به کاتالوگ. */
    public const TYPE_CATALOG_ADDITION = 'catalog_addition';

    /**
     * تغییرِ قیمتِ فراتر از آستانه.
     *
     * ⚠️ برخلافِ دو نوعِ بالا، پذیرشِ این یکی **واقعاً اقدام می‌کند** و قیمت را
     * اعمال می‌کند. دلیلش در `AdminSupplierRequestController::review()` نوشته
     * شده: برای لغو و کاتالوگ یک مسیرِ اجرایی‌ِ دیگر وجود دارد، برای قیمت نه.
     */
    public const TYPE_PRICE_CHANGE = 'price_change';

    public const TYPES = [
        self::TYPE_ORDER_CANCELLATION,
        self::TYPE_CATALOG_ADDITION,
        self::TYPE_PRICE_CHANGE,
    ];

    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    /**
     * ‏`status`, `review_note`, `reviewed_by`, `reviewed_at` عمداً fillable
     * **نیستند** — همان قاعده‌ی `SupplierProfile::STATUS`: تصمیمِ بررسی فقط از
     * مسیرِ ادمین نوشته می‌شود، نه با mass assignment.
     */
    protected $fillable = [
        self::SUPPLIER_USER_ID,
        self::TYPE,
        self::ORDER_ID,
        self::TICKET_ID,
        self::REASON,
        self::PAYLOAD,
    ];

    protected $casts = [
        self::PAYLOAD => 'array',
        self::REVIEWED_AT => 'datetime',
    ];

    /** @return BelongsTo<User, $this> */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(User::class, self::SUPPLIER_USER_ID);
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, self::ORDER_ID);
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class, self::TICKET_ID);
    }

    public function isPending(): bool
    {
        return $this->{self::STATUS} === self::STATUS_PENDING;
    }
}
