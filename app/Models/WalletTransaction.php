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
    public const ID = 'id';

    public const WALLET_ID = 'wallet_id';

    public const ORDER_ID = 'order_id';

    public const AMOUNT = 'amount';

    public const TYPE = 'type';

    public const STATUS = 'status';

    public const DESCRIPTION = 'description';

    public const META = 'meta';

    public const CREATED_AT = 'created_at';

    public const UPDATED_AT = 'updated_at';

    // --- ثابت‌های مقادیر (Enums) ---
    public const TYPE_DEPOSIT = 'deposit';

    public const TYPE_WITHDRAW = 'withdraw';

    public const TYPE_FEE_HOLD = 'fee_hold';

    public const TYPE_FEE_RELEASE = 'fee_release';

    public const STATUS_PENDING = 'pending';

    public const STATUS_CONFIRMED = 'confirmed';

    public const STATUS_FAILED = 'failed';

    public const STATUS_REJECTED = 'rejected';

    /**
     * Escrow status used by the order-financial flow (WalletTransactionEngine).
     * Funds counted in the wallet's balance_hold, awaiting release to available.
     * Mirrors App\Constants\FinancialCodes::STATUS_HOLD.
     */
    public const STATUS_HOLD = 'hold';

    public const METHOD_BANK_TRANSFER = 'bank_transfer';

    public const METHOD_GATEWAY = 'gateway';

    public const PATH_RECEIPTS = 'wallet_receipts';

    protected $fillable = [
        self::WALLET_ID,
        self::ORDER_ID,
        self::AMOUNT,
        self::TYPE,
        self::STATUS,
        self::DESCRIPTION,
        self::META,
    ];

    protected $casts = [
        self::META => 'array',
        self::AMOUNT => 'decimal:2',
    ];

    // --- Accessors ---

    public function getStatusLabelAttribute(): string
    {
        return match ($this->{self::STATUS}) {
            self::STATUS_PENDING => 'در انتظار بررسی',
            self::STATUS_CONFIRMED => 'موفق',
            self::STATUS_HOLD => 'هولد (در انتظار آزادسازی)',
            self::STATUS_REJECTED => 'رد شده',
            self::STATUS_FAILED => 'ناموفق',
            default => 'نامشخص',
        };
    }

    public function getStatusColorAttribute(): string
    {
        return match ($this->{self::STATUS}) {
            self::STATUS_PENDING => 'warning',
            self::STATUS_CONFIRMED => 'success',
            self::STATUS_HOLD => 'info',
            self::STATUS_REJECTED, self::STATUS_FAILED => 'danger',
            default => 'secondary',
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
            ->setDescriptionForEvent(fn (string $eventName) => "تراکنش مالی {$eventName} شد.");
    }
}
