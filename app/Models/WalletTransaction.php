<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class WalletTransaction extends Model
{
    use LogsActivity;

    // --- ثابت‌های نام ستون‌ها ---
    public const
        ID = 'id',
        WALLET_ID = 'wallet_id',
        ORDER_ID = 'order_id',
        AMOUNT = 'amount',
        TYPE = 'type',
        STATUS = 'status',
        DESCRIPTION = 'description',
        META = 'meta',
        CREATED_AT = 'created_at',
        UPDATED_AT = 'updated_at';

    // --- ثابت‌های مقادیر (Enums) ---
    public const
        TYPE_DEPOSIT = 'deposit',
        TYPE_WITHDRAW = 'withdraw',
        TYPE_FEE_HOLD = 'fee_hold',
        TYPE_FEE_RELEASE = 'fee_release',

        STATUS_PENDING = 'pending',
        STATUS_CONFIRMED = 'confirmed',
        STATUS_FAILED = 'failed',
        STATUS_REJECTED = 'rejected';

    public const
        METHOD_BANK_TRANSFER = 'bank_transfer',
        METHOD_GATEWAY = 'gateway',

        PATH_RECEIPTS = 'wallet_receipts';


    protected $fillable = [
        self::WALLET_ID,
        self::ORDER_ID,
        self::AMOUNT,
        self::TYPE,
        self::STATUS,
        self::DESCRIPTION,
        self::META
    ];

    protected $casts = [
        self::META => 'array',
        self::AMOUNT => 'decimal:2',
    ];

    // --- Accessors ---

    public function getStatusLabelAttribute(): string
    {
        return match ($this->{self::STATUS}) {
            self::STATUS_PENDING   => 'در انتظار بررسی',
            self::STATUS_CONFIRMED => 'موفق',
            self::STATUS_REJECTED  => 'رد شده',
            self::STATUS_FAILED    => 'ناموفق',
            default                => 'نامشخص',
        };
    }

    public function getStatusColorAttribute(): string
    {
        return match ($this->{self::STATUS}) {
            self::STATUS_PENDING   => 'warning',
            self::STATUS_CONFIRMED => 'success',
            self::STATUS_REJECTED, self::STATUS_FAILED => 'danger',
            default                => 'secondary',
        };
    }

    protected $appends = ['receipt_details'];

    public function getReceiptDetailsAttribute()
    {
        return $this->meta['receipt_info'] ?? null;
    }

    // --- Relations ---

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class, self::WALLET_ID);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, self::ORDER_ID);
    }

    // --- Activity Log ---

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['status'])
            ->logOnlyDirty()
            ->setDescriptionForEvent(fn(string $eventName) => "تراکنش مالی {$eventName} شد.");
    }
}
