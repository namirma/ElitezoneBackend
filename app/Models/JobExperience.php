<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $user_id
 * @property string $title
 * @property string|null $organization_name
 * @property string|null $project_address
 * @property string $file_path
 * @property \Illuminate\Support\Carbon|null $start_date
 * @property \Illuminate\Support\Carbon|null $end_date
 * @property string $status
 * @property string|null $rejection_reason
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\User $user
 * @method static \Illuminate\Database\Eloquent\Builder|JobExperience newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|JobExperience newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|JobExperience query()
 * @method static \Illuminate\Database\Eloquent\Builder|JobExperience whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|JobExperience whereEndDate($value)
 * @method static \Illuminate\Database\Eloquent\Builder|JobExperience whereFilePath($value)
 * @method static \Illuminate\Database\Eloquent\Builder|JobExperience whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|JobExperience whereOrganizationName($value)
 * @method static \Illuminate\Database\Eloquent\Builder|JobExperience whereProjectAddress($value)
 * @method static \Illuminate\Database\Eloquent\Builder|JobExperience whereRejectionReason($value)
 * @method static \Illuminate\Database\Eloquent\Builder|JobExperience whereStartDate($value)
 * @method static \Illuminate\Database\Eloquent\Builder|JobExperience whereStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder|JobExperience whereTitle($value)
 * @method static \Illuminate\Database\Eloquent\Builder|JobExperience whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|JobExperience whereUserId($value)
 * @mixin \Eloquent
 */
class JobExperience extends Model
{
    use HasFactory;

    public const ID = 'id';

    public const USER_ID = 'user_id';

    public const TITLE = 'title';

    public const ORGANIZATION_NAME = 'organization_name';

    public const PROJECT_ADDRESS = 'project_address';

    public const FILE_PATH = 'file_path';

    public const START_DATE = 'start_date';

    public const END_DATE = 'end_date';

    public const STATUS = 'status';

    public const REJECTION_REASON = 'rejection_reason';

    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    protected $fillable = [
        self::USER_ID,
        self::TITLE,
        self::ORGANIZATION_NAME,
        self::PROJECT_ADDRESS,
        self::FILE_PATH,
        self::START_DATE,
        self::END_DATE,
        self::STATUS,
        self::REJECTION_REASON,
    ];

    protected $casts = [
        self::START_DATE => 'date',
        self::END_DATE => 'date',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, self::USER_ID);
    }
}
