<?php

namespace App\Models;

use App\Traits\PersianDate;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @method static create(array $array)
 * @method static updateOrCreate(array $array, array $array1)
 * @method static where(array $array)
 * @property int $id
 * @property int $user_id
 * @property int $is_verified
 * @property string|null $verified_at
 * @property int $engineer_member
 * @property bool $is_saze90_user
 * @property string|null $license_number شماره پروانه اشتغال
 * @property string|null $supervision_base پایه نظارت
 * @property string|null $design_base پایه طراحی
 * @property string|null $member_number شماره عضویت
 * @property string|null $fields_of_study رشته مهندسی
 * @property int $student
 * @property int|null $province_id
 * @property int|null $activity_province_id
 * @property int|null $city_id
 * @property int|null $activity_city_id
 * @property string|null $university_name
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read string|void $created_at_diff
 * @property-read string|void $created_at_p
 * @property-read string|void $expire_at_days
 * @property-read string|void $updated_at_diff
 * @property-read string|void $updated_at_p
 * @property-read \App\Models\User $user
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EngineerProfile newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EngineerProfile newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EngineerProfile query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EngineerProfile whereActivityCityId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EngineerProfile whereActivityProvinceId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EngineerProfile whereCityId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EngineerProfile whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EngineerProfile whereDesignBase($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EngineerProfile whereEngineerMember($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EngineerProfile whereFieldsOfStudy($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EngineerProfile whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EngineerProfile whereIsSaze90User($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EngineerProfile whereIsVerified($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EngineerProfile whereLicenseNumber($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EngineerProfile whereMemberNumber($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EngineerProfile whereProvinceId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EngineerProfile whereStudent($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EngineerProfile whereSupervisionBase($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EngineerProfile whereUniversityName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EngineerProfile whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EngineerProfile whereUserId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EngineerProfile whereVerifiedAt($value)
 * @mixin \Eloquent
 */
class EngineerProfile extends Model
{
    use HasFactory, PersianDate;

    const ID = 'id';

    const USER_ID = 'user_id';

    /** true وقتی ادمین مدارک الیت یار را تأیید کرد */
    const IS_VERIFIED = 'is_verified';

    const VERIFIED_AT = 'verified_at';

    const ENGINEER_MEMBER = 'engineer_member';

    /** خوداظهاریِ کاربر مبنی بر عضویت در «سازه نود» (سازه ۹۰) */
    const IS_SAZE90_USER = 'is_saze90_user';

    const LICENSE_MEMBER = 'license_number';

    const SUPERVISION_BASE = 'supervision_base';

    const DESIGN_BASE = 'design_base';

    const MEMBER_NUMBER = 'member_number';

    const FIELDS_OF_STUDY = 'fields_of_study';

    const STUDENT = 'student';

    const UNIVERSITY_NAME = 'university_name';

    const PROVINCE_ID = 'province_id';

    const ACTIVITY_PROVINCE_ID = 'activity_province_id';

    const CITY_ID = 'city_id';

    const ACTIVITY_CITY_ID = 'activity_city_id';

    const CREATED_AT = 'created_at';

    const UPDATED_AT = 'updated_at';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        self::USER_ID,
        self::IS_VERIFIED,
        self::VERIFIED_AT,
        self::ENGINEER_MEMBER,
        self::IS_SAZE90_USER,
        self::LICENSE_MEMBER,
        self::SUPERVISION_BASE,
        self::DESIGN_BASE,
        self::MEMBER_NUMBER,
        self::FIELDS_OF_STUDY,
        self::STUDENT,
        self::UNIVERSITY_NAME,
        self::PROVINCE_ID,
        self::ACTIVITY_PROVINCE_ID,
        self::CITY_ID,
        self::ACTIVITY_CITY_ID,
    ];

    protected $casts = [
        self::IS_SAZE90_USER => 'boolean',
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
