<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * راننده‌ی یک حمل‌کننده — بند ۳.۹ و ۴.۶ سند.
 *
 * ⚠️ **راننده کاربر نیست.** نه ردیفی در `users` دارد، نه رمزی، نه توکنی.
 * هر ارتباطی با او از راه پیامک و لینکِ امضاشده است
 * (`GET /api/shipments/{shipment}/arrived`). این تصمیمِ سند است و نه ساده‌سازی:
 * راننده‌ی یک بار ممکن است هرگز دوباره برنگردد، و ساختنِ حساب برای هر راننده
 * جدولِ کاربران را با ردیف‌های مرده پر می‌کرد.
 */
class Driver extends Model
{
    use HasFactory;

    public const ID = 'id';

    public const CARRIER_USER_ID = 'carrier_user_id';

    public const FULL_NAME = 'full_name';

    public const MOBILE = 'mobile';

    public const NATIONAL_CODE = 'national_code';

    public const PLATE_NUMBER = 'plate_number';

    public const VEHICLE_TYPE = 'vehicle_type';

    /** بند ۳.۹: «موبایل با OTP احراز می‌شود.» */
    public const MOBILE_VERIFIED_AT = 'mobile_verified_at';

    public const IS_ACTIVE = 'is_active';

    protected $fillable = [
        self::CARRIER_USER_ID,
        self::FULL_NAME,
        self::MOBILE,
        self::NATIONAL_CODE,
        self::PLATE_NUMBER,
        self::VEHICLE_TYPE,
        self::IS_ACTIVE,
    ];

    /**
     * ‏`MOBILE_VERIFIED_AT` عمداً در `$fillable` نیست.
     *
     * همان قاعده‌ای که `users.debt_balance` دارد: مهرِ احراز فقط از مسیرِ
     * تأییدِ کد نوشته می‌شود. اگر fillable بود، یک `update($request->all())`
     * در آینده می‌توانست راننده‌ی احرازنشده را احرازشده جا بزند.
     */
    protected $casts = [
        self::MOBILE_VERIFIED_AT => 'datetime',
        self::IS_ACTIVE => 'boolean',
    ];

    public function carrier(): BelongsTo
    {
        return $this->belongsTo(User::class, self::CARRIER_USER_ID);
    }

    public function isMobileVerified(): bool
    {
        return $this->{self::MOBILE_VERIFIED_AT} !== null;
    }
}
