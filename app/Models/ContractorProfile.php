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

    const ID = 'id';

    const USER_ID = 'user_id';

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
        self::UPDATED_AT,
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
