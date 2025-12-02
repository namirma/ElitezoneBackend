<?php

namespace App\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserBankAccount extends Model
{
    use HasFactory;

    public const
    ID = 'id',
    USER_ID = 'user_id',
    BANK_NAME = 'bank_name',
    ACCOUNT_HOLDER_NAME = 'account_holder_name',
    SHABA = 'shaba',
    CARD_NUMBER = 'card_number', 
    STATUS = 'status',
    REJECTION_REASON = 'rejection_reason',
    IS_DEFAULT = 'is_default',
    STATUS_PENDING = 'pending',
    STATUS_APPROVED = 'approved',
    STATUS_REJECTED = 'rejected';

    protected $fillable = [
        self::USER_ID,
        self::BANK_NAME,
        self::ACCOUNT_HOLDER_NAME,
        self::SHABA,
        self::CARD_NUMBER,
        self::STATUS,
        self::REJECTION_REASON,
        self::IS_DEFAULT,
    ];

    protected $casts = [
        self::IS_DEFAULT => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, self::USER_ID);
    }
}
