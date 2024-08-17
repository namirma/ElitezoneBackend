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
 */
class UserProfile extends Model
{
    use HasApiTokens, HasFactory, Notifiable, PersianDate;

    CONST
        ID = 'id',
        USER_ID = 'user_id',
        NAME = 'name',
        FAMILY = 'family',
        EMAIL = 'email',
        EMAIL_VERIFIED_AT = 'email_verified_at',
        NATIONAL_CODE = 'national_code',
        NATIONAL_ID = 'national_id',
        BIRTHDAY = 'birthday',
        COMPANY = 'company',
        ECONOMIC_CODE = 'economic_code',
        REGISTER_ID = 'register_id',
        PHONE = 'phone',
        PROVINCE_ID = 'province_id',
        CITY_ID = 'city_id',
        ENTITY = 'entity',
        CREATED_AT = 'created_at',
        UPDATED_AT = 'updated_at';


    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        self::USER_ID,
        self::NAME,
        self::FAMILY,
        self::EMAIL,
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
        self::CREATED_AT,
        self::UPDATED_AT
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
        $this->appends[] = "created_at_p";
        $this->appends[] = "created_at_diff";
        return $this->attributes["created_at"];
    }

    public function getUpdatedAtAttribute()
    {
        $this->appends[] = "updated_at_p";
        $this->appends[] = "updated_at_diff";
        return $this->attributes["updated_at"];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

}
