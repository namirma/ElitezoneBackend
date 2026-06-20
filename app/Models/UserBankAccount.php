<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class UserBankAccount extends Model
{
    use HasFactory, LogsActivity;

    public const ID = 'id';

    public const USER_ID = 'user_id';

    public const BANK_NAME = 'bank_name';

    public const ACCOUNT_HOLDER_NAME = 'account_holder_name';

    public const SHABA = 'shaba';

    public const CARD_NUMBER = 'card_number';

    public const STATUS = 'status';

    public const REJECTION_REASON = 'rejection_reason';

    public const IS_DEFAULT = 'is_default';

    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    protected $fillable = [
        self::USER_ID,
        self::BANK_NAME,
        self::ACCOUNT_HOLDER_NAME,
        self::SHABA,
        self::CARD_NUMBER,
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
                    default => $eventName,
                };

                return "حساب بانکی {$action} شد.";
            });
    }
}
