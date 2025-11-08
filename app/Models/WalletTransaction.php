<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WalletTransaction extends Model
{
    protected $fillable = ['wallet_id', 'order_id', 'amount', 'type', 'status', 'description', 'meta'];
    protected $casts = ['meta' => 'array'];

    public const TYPE_DEPOSIT = 'deposit'; // واریز توسط کاربر (افزودن موجودی)
    public const TYPE_WITHDRAW = 'withdraw'; // برداشت توسط کاربر
    public const TYPE_FEE_HOLD = 'fee_hold'; // هولد شدن سهم مشاور (رزرو)
    public const TYPE_FEE_RELEASE = 'fee_release'; // آزاد شدن سهم مشاور (تایید نهایی)

    public const STATUS_PENDING = 'pending';
    public const STATUS_CONFIRMED = 'confirmed';
    public const STATUS_FAILED = 'failed';

    public function wallet(): BelongsTo {
        return $this->belongsTo(Wallet::class);
    }
    public function order(): BelongsTo {
        return $this->belongsTo(Order::class);
    }
}
