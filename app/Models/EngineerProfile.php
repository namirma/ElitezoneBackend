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
 */
class EngineerProfile extends Model
{
    use HasFactory, PersianDate;

    const
        ID = 'id',
        USER_ID = 'user_id',
        ENGINEER_MEMBER = 'engineer_member',
        LICENSE_MEMBER = 'license_number',
        SUPERVISION_BASE = 'supervision_base',
        DESIGN_BASE = 'design_base',
        MEMBER_NUMBER = 'member_number',
        FIELDS_OF_STUDY = 'fields_of_study',
        STUDENT = 'student',
        UNIVERSITY_NAME = 'university_name',
        PROVINCE_ID = 'province_id',
        ACTIVITY_PROVINCE_ID = 'activity_province_id',
        CITY_ID = 'city_id',
        ACTIVITY_CITY_ID = 'activity_city_id',
        CREATED_AT = 'created_at',
        UPDATED_AT = 'updated_at';


    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        self::USER_ID,
        self::ENGINEER_MEMBER,
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
        self::CREATED_AT,
        self::UPDATED_AT
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
