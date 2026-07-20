<?php

namespace App\Models;

use App\Traits\PersianDate;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @method static updateOrCreate(array $array, array $array1)
 * @method static where(array $array)
 * @property int $id
 * @property int $user_id
 * @property int $is_verified
 * @property string|null $verified_at
 * @property int $engineer_member
 * @property string|null $company_name
 * @property int $not_related
 * @property string|null $license_number شماره پروانه اشتغال
 * @property string|null $supervision_base پایه نظارت
 * @property string|null $design_base پایه طراحی
 * @property int|null $activity_history سابقه فعالیت
 * @property string|null $activity_provinces
 * @property string|null $project_done
 * @property string|null $project_in_progress
 * @property int|null $trusted_contractor
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read string|void $created_at_diff
 * @property-read string|void $created_at_p
 * @property-read string|void $expire_at_days
 * @property-read string|void $updated_at_diff
 * @property-read string|void $updated_at_p
 * @property-read \App\Models\User $user
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ContractorProfile newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ContractorProfile newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ContractorProfile query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ContractorProfile whereActivityHistory($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ContractorProfile whereActivityProvinces($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ContractorProfile whereCompanyName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ContractorProfile whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ContractorProfile whereDesignBase($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ContractorProfile whereEngineerMember($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ContractorProfile whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ContractorProfile whereIsVerified($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ContractorProfile whereLicenseNumber($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ContractorProfile whereNotRelated($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ContractorProfile whereProjectDone($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ContractorProfile whereProjectInProgress($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ContractorProfile whereSupervisionBase($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ContractorProfile whereTrustedContractor($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ContractorProfile whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ContractorProfile whereUserId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ContractorProfile whereVerifiedAt($value)
 * @mixin \Eloquent
 */
class ContractorProfile extends Model
{
    use HasFactory, PersianDate;

    const ID = 'id';

    const USER_ID = 'user_id';

    /** true وقتی ادمین مدارک الیت یار را تأیید کرد */
    const IS_VERIFIED = 'is_verified';

    const VERIFIED_AT = 'verified_at';

    const ENGINEER_MEMBER = 'engineer_member';

    const COMPANY_NAME = 'company_name';

    const NOT_RELATED = 'not_related';

    const LICENSE_NUMBER = 'license_number';

    const SUPERVISION_BASE = 'supervision_base';

    const DESIGN_BASE = 'design_base';

    const ACTIVITY_HISTORY = 'activity_history';

    const PROJECT_DONE = 'project_done';

    const PROJECT_IN_PROGRESS = 'project_in_progress';

    const TRUSTED_CONTRACTOR = 'trusted_contractor';

    const ACTIVITY_PROVINCES = 'activity_provinces';

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
