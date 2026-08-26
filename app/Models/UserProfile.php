<?php

namespace App\Models;

use App\Traits\PersianDate;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

/**
 * @method static updateOrCreate(array $array, array $array1)
 * @method static create(array $array)
 * @method static where(array $array)
 * @method static firstOrCreate(array $array)
 */
class UserProfile extends Model
{
    use HasApiTokens, HasFactory, Notifiable, PersianDate;

    const ID = 'id';

    const USER_ID = 'user_id';

    const NAME = 'name';

    const FAMILY = 'family';

    const AVATAR = 'avatar';

    /**
     * تصویرِ در انتظارِ تأیید — عمداً ستونی جدا از AVATAR.
     *
     * تا لحظه‌ی تأیید، آنچه منتشر می‌شود همان AVATAR است؛ یعنی انتشارِ تصویرِ
     * تأییدنشده مستلزمِ خواندنِ عمدیِ این ستون است و «فراموش‌کردنِ چکِ وضعیت»
     * نمی‌تواند به انتشارِ ناخواسته منجر شود.
     */
    const AVATAR_PENDING = 'avatar_pending';

    const AVATAR_REJECTION_REASON = 'avatar_rejection_reason';

    const AVATAR_REVIEWED_AT = 'avatar_reviewed_at';

    const AVATAR_REVIEWED_BY = 'avatar_reviewed_by';

    const EMAIL = 'email';

    const EMAIL_VERIFICATION_TOKEN = 'email_verification_token';

    const EMAIL_VERIFIED_AT = 'email_verified_at';

    const NATIONAL_CODE = 'national_code';

    const NATIONAL_ID = 'national_id';

    const BIRTHDAY = 'birthday';

    const COMPANY = 'company';

    const ECONOMIC_CODE = 'economic_code';

    const REGISTER_ID = 'register_id';

    const PHONE = 'phone';

    const PROVINCE_ID = 'province_id';

    const CITY_ID = 'city_id';

    const ENTITY = 'entity';

    const CREATED_AT = 'created_at';

    const UPDATED_AT = 'updated_at';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        self::USER_ID,
        self::NAME,
        self::FAMILY,
        self::AVATAR,
        self::AVATAR_PENDING,
        self::AVATAR_REJECTION_REASON,
        self::AVATAR_REVIEWED_AT,
        self::AVATAR_REVIEWED_BY,
        self::EMAIL,
        self::EMAIL_VERIFICATION_TOKEN,
        self::EMAIL_VERIFIED_AT,
        self::NATIONAL_CODE,
        self::NATIONAL_ID,
        self::BIRTHDAY,
        self::COMPANY,
        self::ECONOMIC_CODE,
        self::REGISTER_ID,
        self::PHONE,
        self::PROVINCE_ID,
        self::CITY_ID,
        self::ENTITY,
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        self::EMAIL_VERIFIED_AT => 'datetime',
    ];

    public function getCreatedAtAttribute()
    {
        $this->appends[] = 'created_at_p';
        $this->appends[] = 'created_at_diff';

        return $this->attributes['created_at'];
    }

    public function getUpdatedAtAttribute()
    {
        $this->appends[] = 'updated_at_p';
        $this->appends[] = 'updated_at_diff';

        return $this->attributes['updated_at'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
