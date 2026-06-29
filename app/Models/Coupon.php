<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Coupon extends Model
{
    use HasFactory;

    /* -------------------------------------------------------------------------- */
    /* Constants (Column Names)                                                   */
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

    const CREATED_AT = 'created_at';

    const UPDATED_AT = 'updated_at';

    /* -------------------------------------------------------------------------- */
    /* Constants (Values)                                                         */
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
    ];

    protected $casts = [
        self::AMOUNT => 'decimal:2',
        self::EXPIRES_AT => 'datetime',
        self::USED_AT => 'datetime',
    ];

    /* -------------------------------------------------------------------------- */
    /* Relations                                                                  */
    /* -------------------------------------------------------------------------- */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, self::USER_ID);
    }

    /* -------------------------------------------------------------------------- */
    /* Helper Methods                                                             */
    /* -------------------------------------------------------------------------- */

    /**
     * بررسی اعتبار کوپن
     */
    public function isValid(): bool
    {
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
