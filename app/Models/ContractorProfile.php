<?php

namespace App\Models;

use App\Traits\PersianDate;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @method static updateOrCreate(array $array, array $array1)
 * @method static where(array $array)
 */
class ContractorProfile extends Model
{
    use HasFactory, PersianDate;

    const
        ID = 'id',
        USER_ID = 'user_id',
        ENGINEER_MEMBER = 'engineer_member',
        COMPANY_NAME = 'company_name',
        NOT_RELATED = 'not_related',
        LICENSE_NUMBER = 'license_number',
        SUPERVISION_BASE = 'supervision_base',
        DESIGN_BASE = 'design_base',
        ACTIVITY_HISTORY = 'activity_history',
        PROJECT_DONE = 'project_done',
        PROJECT_IN_PROGRESS = 'project_in_progress',
        TRUSTED_CONTRACTOR = 'trusted_contractor',
        ACTIVITY_PROVINCES = 'activity_provinces',
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
        self::COMPANY_NAME,
        self::NOT_RELATED,
        self::LICENSE_NUMBER,
        self::SUPERVISION_BASE,
        self::DESIGN_BASE,
        self::ACTIVITY_HISTORY,
        self::PROJECT_DONE,
        self::PROJECT_IN_PROGRESS,
        self::TRUSTED_CONTRACTOR,
        self::ACTIVITY_PROVINCES,
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
