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
class EmployerProfile extends Model
{
    use HasFactory ,PersianDate;

    const ID = 'id';

    const USER_ID = 'user_id';

    const PROJECT_DONE = 'project_done';

    const PROJECT_IN_PROGRESS = 'project_in_progress';

    const ACTIVITY_PROVINCES = 'activity_provinces';

    const HAS_ELITE_YAR = 'has_elite_yar';

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
        self::HAS_ELITE_YAR,
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
