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
 * @property int $id
 * @property int $user_id
 * @property string|null $name
 * @property string|null $family
 * @property string|null $avatar
 * @property string|null $email
 * @property string|null $email_verification_token
 * @property \Illuminate\Support\Carbon|null $email_verified_at
 * @property string $national_code
 * @property string|null $birthday
 * @property string|null $national_id
 * @property string|null $company
 * @property string|null $economic_code
 * @property string|null $register_id
 * @property string|null $phone
 * @property int|null $province_id
 * @property int|null $city_id
 * @property string $entity
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read string|void $created_at_diff
 * @property-read string|void $created_at_p
 * @property-read string|void $expire_at_days
 * @property-read string|void $updated_at_diff
 * @property-read string|void $updated_at_p
 * @property-read \Illuminate\Notifications\DatabaseNotificationCollection<int, \Illuminate\Notifications\DatabaseNotification> $notifications
 * @property-read int|null $notifications_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \Laravel\Sanctum\PersonalAccessToken> $tokens
 * @property-read int|null $tokens_count
 * @property-read \App\Models\User $user
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserProfile newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserProfile newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserProfile query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserProfile whereAvatar($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserProfile whereBirthday($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserProfile whereCityId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserProfile whereCompany($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserProfile whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserProfile whereEconomicCode($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserProfile whereEmail($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserProfile whereEmailVerificationToken($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserProfile whereEmailVerifiedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserProfile whereEntity($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserProfile whereFamily($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserProfile whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserProfile whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserProfile whereNationalCode($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserProfile whereNationalId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserProfile wherePhone($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserProfile whereProvinceId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserProfile whereRegisterId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserProfile whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserProfile whereUserId($value)
 * @mixin \Eloquent
 */
class UserProfile extends Model
{
    use HasApiTokens, HasFactory, Notifiable, PersianDate;

    const ID = 'id';

    const USER_ID = 'user_id';

    const NAME = 'name';

    const FAMILY = 'family';

    const AVATAR = 'avatar';

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
