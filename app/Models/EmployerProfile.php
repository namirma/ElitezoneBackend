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
 * @property int $has_consultant
 * @property string $status
 * @property string|null $project_done
 * @property string|null $project_in_progress
 * @property string|null $activity_provinces
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read string|void $created_at_diff
 * @property-read string|void $created_at_p
 * @property-read string|void $expire_at_days
 * @property-read string|void $updated_at_diff
 * @property-read string|void $updated_at_p
 * @property-read \App\Models\User $user
 * @method static \Illuminate\Database\Eloquent\Builder|EmployerProfile newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|EmployerProfile newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|EmployerProfile query()
 * @method static \Illuminate\Database\Eloquent\Builder|EmployerProfile whereActivityProvinces($value)
 * @method static \Illuminate\Database\Eloquent\Builder|EmployerProfile whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|EmployerProfile whereHasConsultant($value)
 * @method static \Illuminate\Database\Eloquent\Builder|EmployerProfile whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|EmployerProfile whereProjectDone($value)
 * @method static \Illuminate\Database\Eloquent\Builder|EmployerProfile whereProjectInProgress($value)
 * @method static \Illuminate\Database\Eloquent\Builder|EmployerProfile whereStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder|EmployerProfile whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|EmployerProfile whereUserId($value)
 * @mixin \Eloquent
 */
class EmployerProfile extends Model
{
    use HasFactory ,PersianDate;

    const ID = 'id';

    const USER_ID = 'user_id';

    const PROJECT_DONE = 'project_done';

    const PROJECT_IN_PROGRESS = 'project_in_progress';

    const ACTIVITY_PROVINCES = 'activity_provinces';

    const HAS_CONSULTANT = 'has_consultant';

    const STATUS = 'status';

    const STATUS_NEUTRAL = 'neutral';

    const STATUS_SUCCESS = 'success';

    const STATUS_ONGOING = 'ongoing';

    const STATUS_FAILURE = 'failure';

    const STATUS_CLOSED = 'closed';

    protected $fillable = [
        self::USER_ID,
        self::PROJECT_DONE,
        self::PROJECT_IN_PROGRESS,
        self::ACTIVITY_PROVINCES,
        self::HAS_CONSULTANT,
        self::STATUS,
    ];

    // public function getCreatedAtAttribute()
    // {
    //     $this->appends[] = "created_at_p";
    //     $this->appends[] = "created_at_diff";
    //     return $this->attributes["created_at"];
    // }

    // public function getUpdatedAtAttribute()
    // {
    //     $this->appends[] = "updated_at_p";
    //     $this->appends[] = "updated_at_diff";
    //     return $this->attributes["updated_at"];
    // }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
