<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Wallet extends Model
{
    public const
    ID = 'id',
    USER_ID = 'user_id',
    BALANCE = 'balance',
    BALANCE_HOLD = 'balance_hold',
    CREATED_AT = 'created_at',
    UPDATED_AT = 'updated_at';

    protected $fillable = [
        self::USER_ID,
        self::BALANCE,
        self::BALANCE_HOLD
    ];

    public function user(): BelongsTo {
        return $this->belongsTo(User::class, self::USER_ID);
    }

    public function transactions(): HasMany {
        return $this->hasMany(WalletTransaction::class);
    }
    
}
