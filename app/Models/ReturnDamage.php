<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * یک قلم هزینه‌ی عودت — بند ۳.۱۱ سند.
 *
 * وضعیت از روی مهرهای زمانی مشتق می‌شود و ستونِ `status` ندارد: جریان
 * سه‌مرحله‌ای است و هر مرحله باید زمانِ خودش را نگه دارد، وگرنه «چه کسی کِی
 * تأیید کرد» از دست می‌رود — همان چیزی که در یک اختلاف مالی لازم می‌شود.
 */
class ReturnDamage extends Model
{
    use HasFactory;

    public const ID = 'id';

    public const SHIPMENT_ID = 'shipment_id';

    public const ORDER_ID = 'order_id';

    public const AMOUNT = 'amount';

    public const DESCRIPTION = 'description';

    public const BACKOFFICE_APPROVED_AT = 'backoffice_approved_at';

    public const BACKOFFICE_APPROVED_BY = 'backoffice_approved_by';

    public const BUYER_APPROVED_AT = 'buyer_approved_at';

    public const SETTLED_AT = 'settled_at';

    public const REJECTED_AT = 'rejected_at';

    public const REJECTION_REASON = 'rejection_reason';

    /** ثبت‌شده، در انتظار بک‌آفیس. */
    public const STATE_PENDING_BACKOFFICE = 'pending_backoffice';

    /** بک‌آفیس تأیید کرده، در انتظار خریدار. */
    public const STATE_PENDING_BUYER = 'pending_buyer';

    /** خریدار تأیید کرده؛ مبلغ روی بدهی نشسته ولی هنوز پرداخت نشده. */
    public const STATE_APPROVED = 'approved';

    /** پرداخت شد و بار آماده‌ی حمل مجدد است. */
    public const STATE_SETTLED = 'settled';

    public const STATE_REJECTED = 'rejected';

    protected $fillable = [
        self::SHIPMENT_ID,
        self::ORDER_ID,
        self::AMOUNT,
        self::DESCRIPTION,
    ];

    protected $casts = [
        self::AMOUNT => 'decimal:0',
        self::BACKOFFICE_APPROVED_AT => 'datetime',
        self::BUYER_APPROVED_AT => 'datetime',
        self::SETTLED_AT => 'datetime',
        self::REJECTED_AT => 'datetime',
    ];

    /**
     * وضعیت مشتق‌شده — ترتیب شرط‌ها اهمیت دارد: رد و تسویه پایانی‌اند و باید
     * پیش از مراحل میانی بررسی شوند.
     */
    public function state(): string
    {
        return match (true) {
            $this->{self::REJECTED_AT} !== null => self::STATE_REJECTED,
            $this->{self::SETTLED_AT} !== null => self::STATE_SETTLED,
            $this->{self::BUYER_APPROVED_AT} !== null => self::STATE_APPROVED,
            $this->{self::BACKOFFICE_APPROVED_AT} !== null => self::STATE_PENDING_BUYER,
            default => self::STATE_PENDING_BACKOFFICE,
        };
    }

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class, self::SHIPMENT_ID);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, self::ORDER_ID);
    }
}
