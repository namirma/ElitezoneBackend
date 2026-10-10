<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Models\Activity;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * @property int $id
 * @property int $user_id
 * @property string $bank_name
 * @property string $account_holder_name
 * @property string|null $shaba
 * @property string|null $card_number
 * @property string $status
 * @property string|null $rejection_reason
 * @property bool $is_default
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Collection<int, Activity> $activities
 * @property-read int|null $activities_count
 * @property-read User $user
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserBankAccount newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserBankAccount newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserBankAccount query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserBankAccount whereAccountHolderName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserBankAccount whereBankName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserBankAccount whereCardNumber($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserBankAccount whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserBankAccount whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserBankAccount whereIsDefault($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserBankAccount whereRejectionReason($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserBankAccount whereShaba($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserBankAccount whereStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserBankAccount whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserBankAccount whereUserId($value)
 *
 * @mixin \Eloquent
 */
class UserBankAccount extends Model
{
    // SoftDeletes: فقط بک‌آفیس حذف می‌کند و حذف نرم است (علی ۱۴۰۵/۰۷/۱۹) — همه‌ی
    // کوئری‌های Eloquent (فهرستِ کاربر، برداشت، تطبیقِ واریز) حسابِ حذف‌شده را نمی‌بینند.
    // `Rule::exists`/`Rule::unique` خامِ DB هستند و این scope را ندارند؛ جدا بسته شده‌اند.
    use HasFactory, LogsActivity, SoftDeletes;

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
