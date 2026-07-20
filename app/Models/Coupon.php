<?php

namespace App\Models;

use App\Traits\PersianDate;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $code
 * @property string $type
 * @property numeric $amount
 * @property int|null $user_id
 * @property \Illuminate\Support\Carbon|null $expires_at
 * @property \Illuminate\Support\Carbon|null $used_at
 * @property string|null $reason
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\User|null $owner
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Coupon newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Coupon newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Coupon query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Coupon whereAmount($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Coupon whereCode($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Coupon whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Coupon whereExpiresAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Coupon whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Coupon whereReason($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Coupon whereType($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Coupon whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Coupon whereUsedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Coupon whereUserId($value)
 * @mixin \Eloquent
 */
class Coupon extends Model
{
    use HasFactory;
    use PersianDate;

    /* -------------------------------------------------------------------------- */
    /* Constants (Column Names) */
    /* -------------------------------------------------------------------------- */
    const TABLE = 'coupons';

    const ID = 'id';

    const CODE = 'code';

    const TYPE = 'type';

    const AMOUNT = 'amount';

    const USER_ID = 'user_id';

    const EXPIRES_AT = 'expires_at';

    const USED_AT = 'used_at';

    const REASON = 'reason';

    const IS_ACTIVE = 'is_active';

    const CREATED_AT = 'created_at';

    const UPDATED_AT = 'updated_at';

    /* -------------------------------------------------------------------------- */
    /* Constants (Values) */
    /* -------------------------------------------------------------------------- */
    const TYPE_PERCENT = 'percent';

    const TYPE_FIXED = 'fixed';

    protected $table = self::TABLE;

    protected $fillable = [
        self::CODE,
        self::TYPE,
        self::AMOUNT,
        self::USER_ID,
        self::EXPIRES_AT,
        self::USED_AT,
        self::REASON,
        self::IS_ACTIVE,
    ];

    protected $casts = [
        self::AMOUNT => 'decimal:2',
        self::IS_ACTIVE => 'boolean',
        self::EXPIRES_AT => 'datetime',
        self::USED_AT => 'datetime',
    ];

    /* -------------------------------------------------------------------------- */
    /* Relations */
    /* -------------------------------------------------------------------------- */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, self::USER_ID);
    }

    /* -------------------------------------------------------------------------- */
    /* Helper Methods */
    /* -------------------------------------------------------------------------- */

    /**
     * بررسی اعتبار کوپن
     */
    public function isValid(): bool
    {
        // ۰. بررسی فعال بودن (غیرفعال‌شده توسط ادمین)
        if (! $this->{self::IS_ACTIVE}) {
            return false;
        }

        // ۱. بررسی استفاده شدن
        if (! is_null($this->{self::USED_AT})) {
            return false;
        }

        // ۲. بررسی تاریخ انقضا
        if ($this->{self::EXPIRES_AT} && $this->{self::EXPIRES_AT}->isPast()) {
            return false;
        }

        return true;
    }
}
