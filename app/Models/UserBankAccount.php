<?php

namespace App\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Activitylog\LogOptions;

class UserBankAccount extends Model
{
    use HasFactory, LogsActivity;

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

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['shaba', 'card_number', 'status'])
            ->logOnlyDirty()
            ->setDescriptionForEvent(function (string $eventName) {
                $action = match ($eventName) {
                    'created' => 'ثبت',
                    'updated' => 'ویرایش',
                    'deleted' => 'حذف',
                    default   => $eventName,
                };
                return "حساب بانکی {$action} شد.";
            });
    }
}
