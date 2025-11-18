<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WalletTransaction extends Model
{
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
    UPDATED_AT = 'updated_at',
    TYPE_DEPOSIT = 'deposit',
    TYPE_WITHDRAW = 'withdraw',
    TYPE_FEE_HOLD = 'fee_hold',
    TYPE_FEE_RELEASE = 'fee_release',

    STATUS_PENDING = 'pending',
    STATUS_CONFIRMED = 'confirmed',
    STATUS_FAILED = 'failed';

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
        self::META => 'array'
    ];

    public function wallet(): BelongsTo {
        return $this->belongsTo(Wallet::class, self::WALLET_ID);
    }

    public function order(): BelongsTo {
        return $this->belongsTo(Order::class, self::ORDER_ID);
    }
}
